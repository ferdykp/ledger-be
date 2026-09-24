<?php

namespace App\Http\Controllers;

use App\Models\TransactionImport;
use App\Services\Ocr\OcrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

class ImportController extends Controller
{
    public function __construct(
        private OcrService $ocr
    ) {}

    public function scan(Request $request): JsonResponse
    {
        $request->validate([
            'document' => [
                'required',
                'file',
                'mimes:jpg,jpeg,png,webp',
                'max:10240',
            ],
        ]);

        $requestId = (string) Str::uuid();
        Log::withContext(['ocr_request_id' => $requestId]);
        $file = $request->file('document');

        $path = null;
        $completed = false;
        Log::info('Ledger OCR received', ['bytes' => $file->getSize()]);

        try {
            $path = $file->store('transaction-imports', 'private');
            $absolute = Storage::disk('private')->path($path);
            $parsed = $this->ocr->extract($absolute);

            $import = TransactionImport::create([
                'user_id' => $request->user()->id,
                'file_path' => $path,
                'ocr_raw_text' => $parsed['raw_text'],
                'parsed_data' => $parsed['draft'],
                'confidence' => $parsed['confidence'],
                'processed_at' => now(),
            ]);

            $completed = true;

            return response()->json([
                'message' => 'Bukti berhasil dibaca.',
                'data' => [
                    'id' => $import->id,
                    'raw_text' => $parsed['raw_text'],
                    'draft' => $parsed['draft'],
                    'confidence' => $parsed['confidence'],
                ],
            ], 201);
        } catch (ProcessTimedOutException $e) {
            Log::warning('Ledger OCR timeout', [
                'user_id' => $request->user()->id,
                'file' => $path,
            ]);

            return response()->json([
                'message' => 'OCR membutuhkan waktu terlalu lama. Coba gunakan screenshot yang lebih jelas atau crop bagian bukti transaksi.',
                'code' => 'OCR_TIMEOUT',
                'request_id' => $requestId,
            ], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'OCR_IMAGE_TOO_LARGE'], 422);
        } catch (\Throwable $e) {
            Log::error('Ledger OCR failed', [
                'user_id' => $request->user()->id,
                'file' => $path,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Bukti belum berhasil dibaca. Coba lagi atau gunakan gambar yang lebih jelas.',
                'code' => 'OCR_FAILED',
                'request_id' => $requestId,
            ], 422);
        } finally {
            if (! $completed && $path) {
                Storage::disk('private')->delete($path);
            }
        }
    }
}
