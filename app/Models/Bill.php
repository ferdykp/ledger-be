<?php
namespace App\Models;use Illuminate\Database\Eloquent\Model;use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Bill extends Model{protected $fillable=['user_id','name','amount','due_date','frequency','status','category','note','reminder_enabled'];protected $casts=['amount'=>'decimal:2','due_date'=>'date','reminder_enabled'=>'boolean'];public function user():BelongsTo{return $this->belongsTo(User::class);}}
