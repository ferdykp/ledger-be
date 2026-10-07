<?php
namespace App\Services\QuickAdd;
use App\Models\User;
use Illuminate\Support\Str;
class QuickAddParser {
 public function parse(User $user,string $text): array {
  $raw=trim($text); $lower=Str::lower($raw); $amount=$this->amount($lower);
  $accounts=$user->accounts()->where('is_archived',false)->get(); $matched=$accounts->filter(fn($a)=>Str::contains($lower,Str::lower($a->name)))->values(); $account=$matched->first(); $related=$this->type($lower)==='transfer'?$matched->get(1):null;
  $type=$this->type($lower); $category=$this->category($user,$lower,$type);
  $missing=[]; if(!$amount)$missing[]='amount'; if(!$account)$missing[]='account'; if($type==='transfer'&&!$related)$missing[]='to_account';
  $confidence=0.25+($amount?0.3:0)+($account?0.25:0)+($category?0.15:0)+($type?0.05:0);
  return ['type'=>$type,'amount'=>$amount,'account_id'=>$account?->id,'account_name'=>$account?->name,'related_account_id'=>$related?->id,'related_account_name'=>$related?->name,'category_id'=>$category?->id,'category_name'=>$category?->name,'note'=>$this->note($raw),'date'=>now()->toDateString(),'missing'=>$missing,'confidence'=>round(min(1,$confidence),2),'source'=>'rule'];
 }
 private function amount(string $s): ?float { if(!preg_match('/(?<!\w)(\d+(?:[.,]\d+)?)\s*(jt|juta|rb|ribu|k)?\b/i',$s,$m)) return null; $n=str_replace(',','.',$m[1]); $v=(float)$n; $u=strtolower($m[2]??''); if(in_array($u,['rb','ribu','k']))$v*=1000; if(in_array($u,['jt','juta']))$v*=1000000; if(!$u && preg_match('/^\d{1,3}(?:\.\d{3})+$/',$m[1]))$v=(float)str_replace('.','',$m[1]); return $v>0?$v:null; }
 private function type(string $s): string { if(preg_match('/\b(transfer|pindah|kirim)\b.*\b(ke|to)\b/u',$s))return 'transfer'; if(preg_match('/\b(gaji|salary|bonus|pemasukan|income|terima|masuk)\b/u',$s))return 'income'; return 'expense'; }
 private function category(User $u,string $s,string $type){ if($type==='transfer')return null; $map=['transport'=>['bensin','pertamina','shell','grab','gojek','parkir','tol'],'makan'=>['makan','kopi','resto','restaurant','warung','cafe','hokben'],'belanja'=>['belanja','tokopedia','shopee','mall'],'tagihan'=>['pln','listrik','internet','indihome','tagihan'],'gaji'=>['gaji','salary','bonus']]; $cats=$u->categories()->where('type',$type)->get(); foreach($map as $hint=>$words){ if(Str::contains($s,$words)){ $c=$cats->first(fn($c)=>Str::contains(Str::lower($c->name),$hint)); if($c)return $c; } } return null; }
 private function note(string $s): string { return Str::limit(trim(preg_replace('/\s+/',' ',$s)),500,''); }
}
