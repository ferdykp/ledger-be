<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Testing\Fluent\Concerns\Has;
use Laravel\Sanctum\HasApiTokens;

// #[Fillable(['name', 'email', 'password'])]
// #[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'currency', 'theme'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['email_verified_at' => 'datetime', 'password' => 'hashed'];

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }
    public function categories()
    {
        return $this->hasMany(Category::class);
    }
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }
    public function budgets()
    {
        return $this->hasMany(Budget::class);
    }
    public function goals()
    {
        return $this->hasMany(Goal::class);
    }
    public function bills()
    {
        return $this->hasMany(Bill::class);
    }
    public function debts()
    {
        return $this->hasMany(Debt::class);
    }
    public function tags()
    {
        return $this->hasMany(Tag::class);
    }
}
