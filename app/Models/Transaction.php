<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'account_id',
        'category_id',
        'related_account_id',
        'type',
        'amount',
        'note',
        'date',
        'attachment_url',
    ];
    protected $casts = ['date' => 'date', 'amount' => 'decimal:2'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function account()
    {
        return $this->belongsTo(Account::class);
    }
    public function relatedAccount()
    {
        return $this->belongsTo(Account::class, 'related_account_id');
    }
    public function category()
    {
        return $this->belongsTo(Category::class);
    }
    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'transaction_tag');
    }
}
