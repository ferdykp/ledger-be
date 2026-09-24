<?php
namespace App\Models; use Illuminate\Database\Eloquent\Model;
class TransactionImport extends Model { protected $fillable=['user_id','source','document_type','file_path','ocr_raw_text','parsed_data','confidence','status','duplicate_transaction_id','processed_at']; protected $casts=['parsed_data'=>'array','confidence'=>'array','processed_at'=>'datetime']; }
