<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Budget extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'amount_limit',
        'period',
        'start_date',
    ];

    protected $casts = [
        'start_date' => 'date',
        'amount_limit' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    // Hitung realisasi pengeluaran dalam rentang bulan budget
    public function getSpentAmountAttribute(): float
    {
        if (array_key_exists("spent_total", $this->attributes)) {
            return (float) $this->attributes["spent_total"];
        }
        if (! $this->category_id) {
            return 0.0;
        }

        $startDate = Carbon::parse($this->start_date)->startOfMonth();
        $endDate = $this->period === 'yearly' ? $startDate->copy()->addYear()->subDay() : $startDate->copy()->endOfMonth();

        return (float) Transaction::where('user_id', $this->user_id)
            ->where('category_id', $this->category_id)
            ->where('type', 'expense')
            ->whereBetween('date', [$startDate->toDateString(), $endDate->toDateString()])
            ->sum('amount');
    }

    // Persentase penggunaan (0% - >100%)
    public function getPercentageAttribute(): float
    {
        if ($this->amount_limit <= 0) {
            return 0.0;
        }

        return round(($this->spent_amount / $this->amount_limit) * 100, 1);
    }

    // Indikator Warna Threshold
    public function getStatusColorAttribute(): string
    {
        $percentage = $this->percentage;

        if ($percentage >= 100) {
            return 'danger';   // Red / Overbudget (#F0473E)
        } elseif ($percentage >= 80) {
            return 'warning';  // Amber / Warning (#FFB020)
        }

        return 'safe';         // Green/Violet / Safe (#17B978 / #6C4CF1)
    }
}
