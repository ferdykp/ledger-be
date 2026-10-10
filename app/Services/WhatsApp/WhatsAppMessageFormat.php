<?php

namespace App\Services\WhatsApp;

use Carbon\CarbonImmutable;

final class WhatsAppMessageFormat
{
    public static function message(string $title, string ...$sections): string
    {
        return '*Ledger · '.$title."*\n\n".implode("\n\n", array_filter($sections, fn ($section) => $section !== ''));
    }

    public static function text(?string $value, int $limit = 180): string
    {
        // User content stays on one line and cannot introduce WhatsApp formatting.
        $value = preg_replace('/[\p{Cc}\p{Cf}\s]+/u', ' ', $value ?? '');
        $value = str_replace(['*', '_', '~', '`'], '', $value);

        return mb_strimwidth(trim($value), 0, $limit, '…');
    }

    public static function money(float|int|string|null $amount): string
    {
        $number = round((float) $amount, 2);
        $decimals = abs($number - round($number)) > 0.00001 ? 2 : 0;

        return ($number < 0 ? '−' : '').'Rp'.number_format(abs($number), $decimals, ',', '.');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('services.whatsapp.timezone', 'Asia/Jakarta'));
    }

    public static function date(string $date): string
    {
        return CarbonImmutable::parse($date)->locale('id')->translatedFormat('j M Y');
    }

    public static function details(array $data): string
    {
        $type = match ($data['type']) {
            'income' => 'Pemasukan', 'transfer' => 'Transfer antar-dompet', default => 'Pengeluaran',
        };
        $wallet = self::text($data['account_name'] ?? 'Dompet tidak tersedia');
        if ($data['type'] === 'transfer') {
            $wallet .= ' → '.self::text($data['related_account_name'] ?? 'Dompet tidak tersedia');
        }
        $lines = [$type.' · *'.self::money($data['amount']).'*', 'Dompet: '.$wallet];
        if ($data['type'] !== 'transfer') {
            $lines[] = 'Kategori: '.self::text($data['category_name'] ?? 'Tanpa kategori');
        }
        $lines[] = 'Tanggal: '.self::date($data['date']);
        if (! empty($data['note'])) {
            $lines[] = 'Catatan: '.self::text($data['note']);
        }

        return implode("\n", $lines);
    }

    public static function actions(): string
    {
        return "*1* — Simpan transaksi\n*3* — Batalkan\n_Berlaku 30 menit sejak ringkasan dibuat._";
    }

    public static function welcome(): string
    {
        return self::message('WhatsApp terhubung', 'Nomor Anda sudah terverifikasi. Mulai catat transaksi dengan pesan singkat.',
            "*Coba kirim*\n• makan 35rb gopay\n• gaji 5jt masuk bca\n• transfer 100rb bca ke gopay",
            'Gunakan nama dompet yang terdaftar di Ledger. Detail transaksi akan ditampilkan untuk Anda konfirmasi sebelum disimpan.',
            'Ketik *bantuan* untuk panduan atau *dompet* untuk melihat akun Anda.');
    }
}
