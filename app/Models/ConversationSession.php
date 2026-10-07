<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class ConversationSession extends Model { protected $fillable=['user_id','channel','state','context','expires_at']; protected $casts=['context'=>'array','expires_at'=>'datetime']; }
