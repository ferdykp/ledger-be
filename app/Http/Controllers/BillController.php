<?php
namespace App\Http\Controllers;use App\Models\Bill;use Illuminate\Http\Request;
class BillController extends Controller{
 private function rules():array{return ['name'=>'required|string|max:120','amount'=>'required|numeric|min:0','due_date'=>'required|date','frequency'=>'required|in:once,weekly,monthly,yearly','status'=>'nullable|in:active,paid,paused','category'=>'nullable|string|max:80','note'=>'nullable|string|max:1000','reminder_enabled'=>'nullable|boolean'];}
 public function index(Request $r){return response()->json(['data'=>$r->user()->bills()->orderBy('due_date')->get()]);}
 public function store(Request $r){$d=$r->validate($this->rules());return response()->json(['data'=>$r->user()->bills()->create($d)],201);}
 public function update(Request $r,Bill $bill){abort_unless($bill->user_id===$r->user()->id,403);$bill->update($r->validate($this->rules()));return response()->json(['data'=>$bill->fresh()]);}
 public function destroy(Request $r,Bill $bill){abort_unless($bill->user_id===$r->user()->id,403);$bill->delete();return response()->noContent();}
 public function markPaid(Request $r,Bill $bill){abort_unless($bill->user_id===$r->user()->id,403);$bill->update(['status'=>'paid']);return response()->json(['data'=>$bill]);}
}
