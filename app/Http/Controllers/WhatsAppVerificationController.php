<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppVerificationCode;
use App\Services\WhatsApp\EvolutionProvider;
use App\Services\WhatsApp\WhatsAppMessageFormat;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class WhatsAppVerificationController extends Controller
{
    public function send(
        Request $request,
        EvolutionProvider $provider
    ): JsonResponse {
        $data = $request->validate([
            'phone_number' => ['required', 'string', 'regex:/^\+?[0-9\s-]{9,20}$/'],
        ]);

        $phone = preg_replace('/\D/', '', $data['phone_number']);

        if (str_starts_with($phone, '0')) {
            $phone = '62'.substr($phone, 1);
        }

        if (str_starts_with($phone, '8')) {
            $phone = '62'.$phone;
        }

        if (! preg_match('/^62[0-9]{8,13}$/', $phone)) {
            throw ValidationException::withMessages([
                'phone_number' => 'Masukkan nomor WhatsApp Indonesia yang valid.',
            ]);
        }

        $user = $request->user();

        $existing = WhatsAppConnection::where('phone_number', $phone)
            ->where('user_id', '!=', $user->id)
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'phone_number' => 'Nomor ini sudah terhubung dengan akun Ledger lain.',
            ]);
        }

        $current = WhatsAppConnection::where('user_id', $user->id)
            ->where('status', 'connected')
            ->first();

        if ($current && $current->phone_number === $phone) {
            throw ValidationException::withMessages([
                'phone_number' => 'Nomor ini sudah terhubung ke akun Anda.',
            ]);
        }

        if ($current) {
            throw ValidationException::withMessages([
                'phone_number' => 'Putuskan koneksi WhatsApp lama sebelum menghubungkan nomor baru.',
            ]);
        }

        $userKey = 'wa-otp:user:'.$user->id;
        $phoneKey = 'wa-otp:phone:'.hash('sha256', $phone);

        if (
            RateLimiter::tooManyAttempts($userKey, 5) ||
            RateLimiter::tooManyAttempts($phoneKey, 5)
        ) {
            return response()->json([
                'message' => 'Terlalu banyak permintaan OTP. Coba lagi nanti.',
            ], 429);
        }

        $cooldownKey = 'wa-otp:cooldown:'.$user->id;

        if (! Cache::add($cooldownKey, true, 60)) {
            return response()->json([
                'message' => 'Tunggu 60 detik sebelum meminta kode baru.',
            ], 429);
        }

        RateLimiter::hit($userKey, 3600);
        RateLimiter::hit($phoneKey, 3600);

        $code = (string) random_int(100000, 999999);

        $otp = WhatsAppVerificationCode::create([
            'user_id' => $user->id,
            'phone_number' => $phone,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(10),
        ]);

        $message = WhatsAppMessageFormat::message('Kode verifikasi',
            '*'.$code.'*', 'Berlaku selama 10 menit. Masukkan kode ini di aplikasi Ledger.',
            'Jangan bagikan kode ini kepada siapa pun. Jika Anda tidak meminta kode, abaikan pesan ini.');

        try {
            $sent = $provider->sendText($phone, $message);
        } catch (\Throwable $e) {
            report($e);
            $sent = false;
        }

        if (! $sent) {
            $otp->delete();
            Cache::forget($cooldownKey);

            return response()->json([
                'message' => 'Kode belum berhasil dikirim. Coba lagi nanti.',
            ], 502);
        }

        WhatsAppVerificationCode::where('user_id', $user->id)
            ->where('id', '<', $otp->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        return response()->json([
            'message' => 'Kode verifikasi telah dikirim.',
            'data' => [
                'phone_number' => $phone,
                'expires_in' => 600,
                'resend_after' => 60,
            ],
        ]);
    }

    public function verify(Request $request, EvolutionProvider $provider): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        try {
            $result = DB::transaction(function () use ($user, $data) {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $otp = WhatsAppVerificationCode::where('user_id', $user->id)
                    ->whereNull('used_at')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                if (! $otp || $otp->expires_at->lessThanOrEqualTo(now())) {
                    return ['error' => 'Kode tidak ditemukan atau sudah kedaluwarsa.'];
                }

                if ($otp->attempts >= 5) {
                    return ['error' => 'Batas percobaan OTP telah tercapai.'];
                }

                $otp->increment('attempts');

                if (! Hash::check($data['code'], $otp->code_hash)) {
                    return ['error' => 'Kode verifikasi salah.'];
                }

                $taken = WhatsAppConnection::where(
                    'phone_number',
                    $otp->phone_number
                )
                    ->where('user_id', '!=', $user->id)
                    ->exists();

                if ($taken) {
                    return ['error' => 'Nomor ini sudah digunakan akun lain.'];
                }

                $connection = WhatsAppConnection::where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();

                if ($connection && $connection->status === 'connected') {
                    return ['error' => 'Akun sudah memiliki WhatsApp terhubung.'];
                }

                WhatsAppConnection::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'phone_number' => $otp->phone_number,
                        'provider' => 'evolution',
                        'status' => 'connected',
                        'verified_at' => now(),
                    ]
                );

                $otp->update(['used_at' => now()]);

                return ['success' => true, 'phone' => $otp->phone_number];
            });

        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages(['code' => 'Nomor ini baru saja dihubungkan ke akun lain. Gunakan nomor lain atau periksa koneksi Anda.']);
        }

        if (isset($result['error'])) {
            throw ValidationException::withMessages([
                'code' => $result['error'],
            ]);
        }

        // Kirim setelah transaksi database commit; kegagalan pesan tidak membatalkan verifikasi.
        try {
            $provider->sendText($result['phone'], WhatsAppMessageFormat::welcome());
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'message' => 'WhatsApp berhasil diverifikasi.',
            'data' => ['connected' => true],
        ]);
    }
}
