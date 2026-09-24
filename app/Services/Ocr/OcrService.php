<?php
namespace App\Services\Ocr;
use Carbon\Carbon;
class OcrService {
 public function extract(string $path): array { $text=$this->runTesseract($path); return ['raw_text'=>$text,'draft'=>$this->parse($text),'confidence'=>['overall'=>$text?0.70:0.0]]; }
 private function runTesseract(string $path): string { $bin=config('services.ocr.tesseract_binary', 'tesseract'); if(!function_exists('shell_exec')) return ''; $cmd=escapeshellcmd($bin).' '.escapeshellarg($path).' stdout -l eng 2>/dev/null'; return trim((string)shell_exec($cmd)); }
 private function parse(string $text): array { $amount=null; preg_match_all('/(?:Rp\.?\s*)?([0-9]{1,3}(?:[.][0-9]{3})+(?:,[0-9]{2})?|[0-9]{4,})/i',$text,$m); $nums=array_map(fn($x)=>(float)str_replace([".",","],["","."],$x),$m[1]??[]); if($nums)$amount=max($nums); $date=null; if(preg_match('/(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})/',$text,$d)){try{$date=Carbon::create((int)$d[3],(int)$d[2],(int)$d[1])->format('Y-m-d');}catch(\Throwable $e){}} return ['amount'=>$amount,'date'=>$date?:now()->format('Y-m-d'),'note'=>'Import OCR']; }
}
