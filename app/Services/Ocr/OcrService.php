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

        $stage = 'preprocess';
        $timings = [];
        try {
            $prepared = $this->preprocess($path);
            $timings['preprocess_ms'] = round((microtime(true) - $startedAt) * 1000);
            $stage = 'tesseract';
            $ocrStarted = microtime(true);
            // One pass only: repeated passes can outlive the HTTP request.
            $text = $this->runTesseract($prepared);
            $timings['tesseract_ms'] = round((microtime(true) - $ocrStarted) * 1000);
            if ($text === '') {
                throw new \RuntimeException('Teks tidak berhasil dibaca dari gambar.');
            }
            $stage = 'parse';
            $draft = $this->parse($text);

            Log::info('Ledger OCR completed', $timings + [
                'processing_ms' => round((microtime(true) - $startedAt) * 1000),
            ]);

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
        } catch (\Throwable $e) {
            Log::warning('Ledger OCR stage failed', $timings + [
                'stage' => $stage,
                'processing_ms' => round((microtime(true) - $startedAt) * 1000),
                'exception' => $e::class,
            ]);
            throw $e;
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
        $info = @getimagesize($path);
        if (! $info || $info[0] < 1 || $info[1] < 1) {
            throw new \RuntimeException('Gambar tidak valid.');
        }
        // Reject oversized decoded images before GD allocates memory.
        if ($info[0] * $info[1] > 20_000_000) {
            throw new \InvalidArgumentException('Resolusi gambar terlalu besar. Crop atau kecilkan gambar hingga maksimal 20 megapiksel.');
        }
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('PHP GD diperlukan untuk preprocessing OCR.');
        }
        $image = match ($info['mime'] ?? '') {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };
        if (! $image) {
            throw new \RuntimeException('Gambar tidak dapat dibaca.');
        }
        $output = null;
        $resized = null;
        try {
            // Bound both dimensions; never enlarge a tall mobile screenshot.
            $scale = min(1, 2000 / max($info[0], $info[1]));
            $width = max(1, (int) round($info[0] * $scale));
            $height = max(1, (int) round($info[1] * $scale));
            $resized = imagecreatetruecolor($width, $height);
            imagefill($resized, 0, 0, imagecolorallocate($resized, 255, 255, 255));
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
            imagefilter($resized, IMG_FILTER_GRAYSCALE);
            $output = tempnam(sys_get_temp_dir(), 'ledger_ocr_');
            if ($output === false || ! imagepng($resized, $output, 1)) {
                throw new \RuntimeException('Gagal menyiapkan gambar OCR.');
            }

            return $output;
        } catch (\Throwable $e) {
            if ($output && is_file($output)) {
                @unlink($output);
            }
            throw $e;
        } finally {
            imagedestroy($image);
            if ($resized) {
                imagedestroy($resized);
            }
        }
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
        $process->setEnv(['OMP_THREAD_LIMIT' => '1']);
        $process->setTimeout(max(1, min(20, (float) config('services.ocr.timeout', 15))));

        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(
                trim($process->getErrorOutput())
                    ?: 'Tesseract gagal memproses gambar.'
            );
        }

        return trim($process->getOutput());
    }

    private function parse(string $text): array
    {
        $normalized = preg_replace(
            "/[ \t]+/",
            ' ',
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

        if (! $candidates) {
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
        if (! preg_match(
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
                            '/^.*?'.preg_quote($keyword, '/').'\s*:?\s*/i',
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

        if (! empty($draft['amount'])) {
            $score += 0.40;
        }

        if (! empty($draft['date'])) {
            $score += 0.20;
        }

        if (! empty($draft['merchant'])) {
            $score += 0.15;
        }

        if (! empty($draft['reference_number'])) {
            $score += 0.15;
        }

        if (strlen($text) >= 30) {
            $score += 0.10;
        }

        return min(1, $score);
    }
}
