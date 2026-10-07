<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class WhatsAppConnection extends Model { protected $fillable=['user_id','phone_number','provider','status','verified_at','last_message_at']; protected $casts=['verified_at'=>'datetime','last_message_at'=>'datetime']; public function user(){ return $this->belongsTo(User::class); } }
