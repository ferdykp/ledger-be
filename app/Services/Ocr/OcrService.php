<?php

namespace App\Services\Ocr;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class OcrService
{
    public function extract(string $path): array
    {
        $startedAt = microtime(true);

        try {
            $prepared = $this->preprocess($path);

            $results = [];

            foreach ([6, 11] as $psm) {
                try {
                    $text = $this->runTesseract($prepared, $psm);

                    if ($text !== '') {
                        $results[] = [
                            'text' => $text,
                            'score' => $this->scoreText($text),
                        ];
                    }
                } catch (\Throwable $e) {
                    Log::warning('OCR attempt failed', [
                        'psm' => $psm,
                        'message' => $e->getMessage(),
                    ]);
                }
            }

            usort(
                $results,
                fn($a, $b) => $b['score'] <=> $a['score']
            );

            $text = $results[0]['text'] ?? '';

            if ($text === '') {
                throw new \RuntimeException(
                    'Teks tidak berhasil dibaca dari gambar.'
                );
            }

            $draft = $this->parse($text);

            return [
                'raw_text' => $text,

                'draft' => $draft,

                'confidence' => [
                    'overall' => $this->calculateConfidence($draft, $text),
                    'processing_ms' => round(
                        (microtime(true) - $startedAt) * 1000
                    ),
                ],
            ];
        } finally {
            if (
                isset($prepared) &&
                $prepared !== $path &&
                is_file($prepared)
            ) {
                @unlink($prepared);
            }
        }
    }

    private function preprocess(string $path): string
    {
        if (!extension_loaded('gd')) {
            return $path;
        }

        $info = @getimagesize($path);

        if (!$info) {
            return $path;
        }

        $mime = $info['mime'] ?? '';

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp')
                ? @imagecreatefromwebp($path)
                : false,
            default => false,
        };

        if (!$image) {
            return $path;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        /*
         * Screenshot/foto m-banking sering memiliki dokumen kecil.
         * Jangan mengecilkan gambar yang sudah kecil.
         */
        $targetWidth = min(
            2200,
            max(1400, $width)
        );

        $scale = $targetWidth / $width;
        $targetHeight = (int) round($height * $scale);

        $resized = imagecreatetruecolor(
            $targetWidth,
            $targetHeight
        );

        imagecopyresampled(
            $resized,
            $image,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            $width,
            $height
        );

        imagefilter($resized, IMG_FILTER_GRAYSCALE);

        imagefilter(
            $resized,
            IMG_FILTER_CONTRAST,
            -35
        );

        /*
         * Sharpen ringan.
         */
        if (function_exists('imageconvolution')) {
            $matrix = [
                [0, -1, 0],
                [-1, 5, -1],
                [0, -1, 0],
            ];

            imageconvolution(
                $resized,
                $matrix,
                1,
                0
            );
        }

        $tmp = tempnam(
            sys_get_temp_dir(),
            'ledger_ocr_'
        );

        $output = $tmp . '.png';

        @unlink($tmp);

        imagepng(
            $resized,
            $output,
            9
        );

        imagedestroy($image);
        imagedestroy($resized);

        return $output;
    }

    private function runTesseract(
        string $path,
        int $psm = 6
    ): string {
        $binary = config(
            'services.ocr.tesseract_binary',
            'tesseract'
        );

        $process = new Process([
            $binary,
            $path,
            'stdout',
            '-l',
            'eng',
            '--oem',
            '1',
            '--psm',
            (string) $psm,
            '-c',
            'preserve_interword_spaces=1',
        ]);

        /*
         * Tidak boleh membuat request menggantung.
         */
        $process->setTimeout(12);

        $process->run();

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(
                trim($process->getErrorOutput())
                    ?: 'Tesseract gagal memproses gambar.'
            );
        }

        return trim($process->getOutput());
    }

    private function scoreText(string $text): int
    {
        $score = strlen($text);

        $keywords = [
            'total',
            'bayar',
            'amount',
            'nominal',
            'idr',
            'rp',
            'tanggal',
            'date',
            'referensi',
            'reference',
            'transaksi',
            'pembayaran',
        ];

        $lower = strtolower($text);

        foreach ($keywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                $score += 100;
            }
        }

        return $score;
    }

    private function parse(string $text): array
    {
        $normalized = preg_replace(
            "/[ \t]+/",
            " ",
            $text
        );

        $lines = array_values(
            array_filter(
                array_map(
                    'trim',
                    preg_split('/\R/u', $normalized)
                )
            )
        );

        return [
            'amount' => $this->parseAmount($lines),
            'date' => $this->parseDate($text),
            'merchant' => $this->parseMerchant($lines),
            'reference_number' => $this->parseReference($lines),
            'type' => $this->detectType($text),
            'note' => $this->parseNote($lines),
        ];
    }

    private function parseAmount(array $lines): ?float
    {
        /*
         * PRIORITAS LABEL.
         *
         * Sangat penting:
         * jangan menggunakan angka terbesar dari dokumen.
         * Nomor referensi bank bisa jauh lebih besar dari nominal.
         */
        $priority = [
            'total bayar',
            'total pembayaran',
            'total payment',
            'grand total',
            'jumlah bayar',
            'jumlah pembayaran',
            'amount paid',
            'total amount',
            'nominal transaksi',
            'nominal',
            'amount',
            'total',
        ];

        foreach ($priority as $keyword) {
            foreach ($lines as $line) {
                if (
                    str_contains(
                        strtolower($line),
                        $keyword
                    )
                ) {
                    $amount = $this->extractMoney($line);

                    if ($amount !== null) {
                        return $amount;
                    }
                }
            }
        }

        /*
         * Fallback: hanya angka yang jelas memiliki
         * Rp / IDR.
         */
        $candidates = [];

        foreach ($lines as $line) {
            if (
                preg_match(
                    '/\b(?:IDR|RP)\b/i',
                    $line
                )
            ) {
                $amount = $this->extractMoney($line);

                if (
                    $amount !== null &&
                    $amount > 0 &&
                    $amount < 1_000_000_000
                ) {
                    $candidates[] = $amount;
                }
            }
        }

        if (!$candidates) {
            return null;
        }

        /*
         * Biasanya total pembayaran berada di bagian bawah
         * sehingga candidate terakhir lebih aman daripada max().
         */
        return end($candidates);
    }

    private function extractMoney(string $value): ?float
    {
        if (!preg_match(
            '/(?:IDR|RP)?\s*([0-9][0-9.,]*)/i',
            $value,
            $match
        )) {
            return null;
        }

        $number = $match[1];

        /*
         * IDR 49,300.00
         */
        if (
            preg_match(
                '/^\d{1,3}(?:,\d{3})+(?:\.\d{1,2})?$/',
                $number
            )
        ) {
            return (float) str_replace(',', '', $number);
        }

        /*
         * Rp 49.300,00
         */
        if (
            preg_match(
                '/^\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?$/',
                $number
            )
        ) {
            $number = str_replace('.', '', $number);
            $number = str_replace(',', '.', $number);

            return (float) $number;
        }

        /*
         * 49300 / 49300.00
         */
        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $number)) {
            return (float) $number;
        }

        return null;
    }

    private function parseDate(string $text): string
    {
        $patterns = [
            /*
             * 22/09/2026
             */
            '/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](20\d{2})\b/',

            /*
             * 22 Sep 2026
             */
            '/\b(\d{1,2})\s+(Jan|Feb|Mar|Apr|Mei|May|Jun|Jul|Agu|Aug|Sep|Okt|Oct|Nov|Des|Dec)[a-z]*\s+(20\d{2})\b/i',
        ];

        if (preg_match($patterns[0], $text, $m)) {
            try {
                return Carbon::create(
                    (int) $m[3],
                    (int) $m[2],
                    (int) $m[1]
                )->format('Y-m-d');
            } catch (\Throwable) {
            }
        }

        if (preg_match($patterns[1], $text, $m)) {
            $months = [
                'jan' => 1,
                'feb' => 2,
                'mar' => 3,
                'apr' => 4,
                'mei' => 5,
                'may' => 5,
                'jun' => 6,
                'jul' => 7,
                'agu' => 8,
                'aug' => 8,
                'sep' => 9,
                'okt' => 10,
                'oct' => 10,
                'nov' => 11,
                'des' => 12,
                'dec' => 12,
            ];

            $month = $months[strtolower(substr($m[2], 0, 3))] ?? null;

            if ($month) {
                try {
                    return Carbon::create(
                        (int) $m[3],
                        $month,
                        (int) $m[1]
                    )->format('Y-m-d');
                } catch (\Throwable) {
                }
            }
        }

        /*
         * Jangan berpura-pura OCR menemukan tanggal hari ini.
         */
        return '';
    }

    private function parseMerchant(array $lines): ?string
    {
        $keywords = [
            'pembayaran ke',
            'transfer ke',
            'merchant',
            'penerima',
            'kepada',
        ];

        foreach ($lines as $line) {
            $lower = strtolower($line);

            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    $merchant = trim(
                        preg_replace(
                            '/^.*?' . preg_quote($keyword, '/') . '\s*:?\s*/i',
                            '',
                            $line
                        )
                    );

                    if (
                        $merchant !== '' &&
                        strlen($merchant) > 2
                    ) {
                        return $merchant;
                    }
                }
            }
        }

        return null;
    }

    private function parseReference(array $lines): ?string
    {
        foreach ($lines as $index => $line) {
            if (
                preg_match(
                    '/(?:no\.?\s*)?(?:referensi|reference|ref\.?)\s*:?\s*([A-Z0-9-]{6,})/i',
                    $line,
                    $m
                )
            ) {
                return $m[1];
            }

            if (
                preg_match(
                    '/(?:referensi|reference|ref)/i',
                    $line
                ) &&
                isset($lines[$index + 1]) &&
                preg_match(
                    '/([A-Z0-9-]{6,})/i',
                    $lines[$index + 1],
                    $m
                )
            ) {
                return $m[1];
            }
        }

        return null;
    }

    private function detectType(string $text): string
    {
        $lower = strtolower($text);

        $expenseKeywords = [
            'pembayaran',
            'bayar',
            'qris',
            'purchase',
            'merchant',
            'total bayar',
        ];

        foreach ($expenseKeywords as $keyword) {
            if (str_contains($lower, $keyword)) {
                return 'expense';
            }
        }

        return 'expense';
    }

    private function parseNote(array $lines): string
    {
        $merchant = $this->parseMerchant($lines);

        return $merchant
            ? "Pembayaran {$merchant}"
            : 'Import dari bukti transaksi';
    }

    private function calculateConfidence(
        array $draft,
        string $text
    ): float {
        $score = 0;

        if (!empty($draft['amount'])) {
            $score += 0.40;
        }

        if (!empty($draft['date'])) {
            $score += 0.20;
        }

        if (!empty($draft['merchant'])) {
            $score += 0.15;
        }

        if (!empty($draft['reference_number'])) {
            $score += 0.15;
        }

        if (strlen($text) >= 30) {
            $score += 0.10;
        }

        return min(1, $score);
    }
}
