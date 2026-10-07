<?php
namespace App\Services\AI;
use App\Models\User;
use Illuminate\Support\Facades\Http;
class GroqParser {
 public function parse(User $user,string $text): ?array { $key=config('services.groq.key'); if(!$key)return null; $accounts=$user->accounts()->where('is_archived',false)->pluck('name')->values()->all(); $categories=$user->categories()->get(['name','type'])->toArray(); $prompt='Parse Indonesian personal-finance message. Never invent an account/category. Return JSON only with keys type,amount,account_name,category_name,note,date. type must income|expense|transfer. Available accounts: '.json_encode($accounts).'. Available categories: '.json_encode($categories).'. Message: '.$text; $r=Http::timeout(12)->withToken($key)->post('https://api.groq.com/openai/v1/chat/completions',['model'=>config('services.groq.model'),'temperature'=>0,'response_format'=>['type'=>'json_object'],'messages'=>[['role'=>'system','content'=>'You are a strict transaction parser. Output valid JSON only.'],['role'=>'user','content'=>$prompt]]]); if(!$r->successful())return null; return json_decode(data_get($r->json(),'choices.0.message.content',''),true) ?: null; }
}
