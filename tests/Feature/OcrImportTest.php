<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OcrImportTest extends TestCase
{
    use RefreshDatabase;

    private string $binary;

    private string $trace;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->binary = tempnam(sys_get_temp_dir(), 'ocr_test_');
        $this->trace = tempnam(sys_get_temp_dir(), 'ocr_trace_');
        config(['services.ocr.tesseract_binary' => $this->binary]);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        @unlink($this->binary);
        @unlink($this->trace);
        parent::tearDown();
    }

    private function fakeTesseract(string $body): void
    {
        $trace = var_export($this->trace, true);
        file_put_contents($this->binary, '#!'.PHP_BINARY."\n<?php\n".
            'file_put_contents('.$trace.', json_encode([$argv[1], getimagesize($argv[1]), getenv("OMP_THREAD_LIMIT")])."\\n", FILE_APPEND);'."\n".$body);
        chmod($this->binary, 0700);
    }

    public function test_scan_runs_once_resizes_tall_image_and_returns_draft(): void
    {
        $this->fakeTesseract('echo "Total bayar Rp 49.300,00\n22 Sep 2026\nMerchant: Toko Uji";');
        $this->postJson('/api/imports/scan', [
            'document' => UploadedFile::fake()->image('invoice.png', 1000, 4000),
        ])->assertCreated()->assertJsonPath('data.draft.amount', 49300)
            ->assertJsonPath('data.draft.date', '2026-09-22');
        $calls = file($this->trace, FILE_IGNORE_NEW_LINES);
        $this->assertCount(1, $calls);
        [$prepared, $dimensions, $threads] = json_decode($calls[0], true);
        $this->assertSame([500, 2000], [$dimensions[0], $dimensions[1]]);
        $this->assertSame('1', $threads);
        $this->assertFileDoesNotExist($prepared);
        $this->assertDatabaseCount('transaction_imports', 1);
        $this->assertCount(1, Storage::disk('private')->allFiles());
    }

    public function test_timeout_is_not_swallowed_or_retried_and_files_are_cleaned(): void
    {
        config(['services.ocr.timeout' => 1]);
        $this->fakeTesseract('sleep(3);');
        $this->postJson('/api/imports/scan', [
            'document' => UploadedFile::fake()->image('invoice.jpg'),
        ])->assertStatus(422)->assertJsonPath('code', 'OCR_TIMEOUT');
        $calls = file($this->trace, FILE_IGNORE_NEW_LINES);
        $this->assertCount(1, $calls);
        $this->assertFileDoesNotExist(json_decode($calls[0], true)[0]);
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertDatabaseCount('transaction_imports', 0);
    }

    public function test_empty_ocr_text_returns_failure_without_creating_import(): void
    {
        $this->fakeTesseract('echo " ";');
        $this->postJson('/api/imports/scan', [
            'document' => UploadedFile::fake()->image('blank.png'),
        ])->assertStatus(422)->assertJsonPath('code', 'OCR_FAILED');
        $this->assertSame([], Storage::disk('private')->allFiles());
        $this->assertDatabaseCount('transaction_imports', 0);
    }

    public function test_unsupported_file_is_rejected_before_ocr(): void
    {
        $this->postJson('/api/imports/scan', [
            'document' => UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf'),
        ])->assertUnprocessable()->assertJsonValidationErrors('document');
        $this->assertSame('', file_get_contents($this->trace));
    }
}
