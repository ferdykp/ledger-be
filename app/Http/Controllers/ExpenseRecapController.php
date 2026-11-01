<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Support\DecimalMath;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ExpenseRecapController extends Controller
{
    public function __invoke(Request $request)
    {
        $data = $request->validate([
            'month' => ['nullable', 'required_without:from', 'prohibits:from,to', 'date_format:Y-m'],
            'from' => ['nullable', 'required_without:month', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        $monthly = ! empty($data['month']);
        $start = $monthly ? CarbonImmutable::createFromFormat('!Y-m', $data['month']) : CarbonImmutable::parse($data['from']);
        $end = $monthly ? $start->endOfMonth() : CarbonImmutable::parse($data['to']);
        $base = Transaction::where('transactions.user_id', $request->user()->id)->whereNull('transactions.financial_purpose')->whereBetween('transactions.date', [$start->toDateString(), $end->toDateString()]);
        $totals = (clone $base)->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense, COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income")->first();
        $expenses = (clone $base)->where('transactions.type', 'expense');
        $count = (clone $expenses)->count();
        $categories = (clone $expenses)->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->groupBy('transactions.category_id', 'categories.name')
            ->selectRaw("transactions.category_id, COALESCE(categories.name, 'Tanpa kategori') AS name, SUM(transactions.amount) AS amount, COUNT(*) AS count")
            ->orderByDesc('amount')->get()->map(fn ($category) => [
                'id' => $category->category_id, 'name' => $category->name, 'amount' => DecimalMath::add((string) $category->amount, '0.00'),
                'count' => (int) $category->count,
                'percentage' => DecimalMath::compare((string) $totals->expense, '0.00') > 0 ? (float) bcdiv(bcmul((string) $category->amount, '1000', 0), (string) $totals->expense, 0) / 10 : 0,
            ]);
        $transactions = (clone $expenses)->with(['account', 'category', 'relatedAccount'])->orderByDesc('date')->orderByDesc('id')->paginate(20);
        $days = (int) $start->startOfDay()->diffInDays($end->startOfDay()) + 1;
        $bucket = $days > 62 ? 'SUBSTR(date, 1, 7)' : 'date';
        $trend = (clone $base)->whereIn('type', ['income', 'expense'])->selectRaw("{$bucket} AS period, SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense, SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income")
            ->groupByRaw($bucket)->orderBy('period')->get()->map(fn ($row) => ['date' => substr($row->period, 0, 10), 'expense' => DecimalMath::add((string) $row->expense, '0.00'), 'income' => DecimalMath::add((string) $row->income, '0.00')]);
        $previous = $monthly ? DecimalMath::add((string) Transaction::where('user_id', $request->user()->id)->whereNull('financial_purpose')->where('type', 'expense')
            ->whereBetween('date', [$start->subMonth()->toDateString(), $start->subDay()->toDateString()])->sum('amount'), '0.00') : null;

        return response()->json(['data' => [
            'from' => $start->toDateString(), 'to' => $end->toDateString(), 'days' => $days,
            'expense' => DecimalMath::add((string) $totals->expense, '0.00'), 'income' => DecimalMath::add((string) $totals->income, '0.00'),
            'net' => DecimalMath::subtract((string) $totals->income, (string) $totals->expense),
            'count' => $count, 'daily_average' => bcdiv((string) $totals->expense, (string) $days, 2),
            'trend' => $trend, 'trend_interval' => $days > 62 ? 'month' : 'day',
            'previous_expense' => $previous, 'categories' => $categories,
            'transactions' => TransactionResource::collection($transactions)->response()->getData(true),
        ]]);
    }
}
