<?php

namespace App\Http\Controllers;

use App\Models\ConversationSession;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppPairingCode;
use App\Models\WhatsAppVerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WhatsAppIntegrationController extends Controller
{
    public function status(Request $r): JsonResponse
    {
        $c = WhatsAppConnection::where('user_id', $r->user()->id)->first();

        return response()->json(['data' => $c ? ['connected' => $c->status === 'connected', 'phone_number' => $c->phone_number, 'verified_at' => $c->verified_at] : ['connected' => false]]);
    }

    public function pairing(Request $r): JsonResponse
    {
        return response()->json(['message' => 'Gunakan verifikasi OTP melalui Pengaturan > WhatsApp.'], 410);
    }

    public function disconnect(Request $r): JsonResponse
    {
        DB::transaction(function () use ($r) {
            User::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();
            WhatsAppConnection::where('user_id', $r->user()->id)->delete();
            ConversationSession::where('user_id', $r->user()->id)->delete();
            WhatsAppPairingCode::where('user_id', $r->user()->id)->whereNull('used_at')->update(['used_at' => now()]);
            WhatsAppVerificationCode::where('user_id', $r->user()->id)->whereNull('used_at')->update(['used_at' => now()]);
        });

        return response()->json(['message' => 'WhatsApp berhasil diputuskan.']);
    }
}
