<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;
use App\Notifications\ResetPasswordNotification;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'currency', 'theme', 'avatar'];
    protected $hidden = ['password', 'remember_token'];
    protected $casts = ['email_verified_at' => 'datetime', 'password' => 'hashed'];

    // Append URL Avatar
    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute(): string
    {
        if ($this->avatar) {
            return asset('storage/' . $this->avatar);
        }
        // Fallback Avatar Default (UI Avatars)
        return 'https://ui-avatars.com/api/?name=' . urlencode($this->name) . '&background=6C4CF1&color=fff';
    }

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

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

    public function bills() { return $this->hasMany(\App\Models\Bill::class); }
}
