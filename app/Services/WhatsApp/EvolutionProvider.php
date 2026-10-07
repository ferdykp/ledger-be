<?php
namespace App\Services\WhatsApp;
use Illuminate\Support\Facades\Http;
class EvolutionProvider { public function sendText(string $phone,string $text): bool { $base=rtrim((string)config('services.evolution.url'),'/'); $instance=config('services.evolution.instance'); $key=config('services.evolution.key'); if(!$base||!$instance||!$key)return false; $r=Http::timeout(10)->withHeaders(['apikey'=>$key])->post("{$base}/message/sendText/{$instance}",['number'=>$phone,'text'=>$text]); return $r->successful(); } }
