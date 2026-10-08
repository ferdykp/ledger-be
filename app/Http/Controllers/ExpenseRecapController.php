<?php

namespace App\Http\Controllers;

use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
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
        $base = Transaction::where('transactions.user_id', $request->user()->id)->whereBetween('transactions.date', [$start->toDateString(), $end->toDateString()]);
        $totals = (clone $base)->selectRaw("COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense, COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income")->first();
        $expenses = (clone $base)->where('transactions.type', 'expense');
        $count = (clone $expenses)->count();
        $categories = (clone $expenses)->leftJoin('categories', 'transactions.category_id', '=', 'categories.id')
            ->groupBy('transactions.category_id', 'categories.name')
            ->selectRaw("transactions.category_id, COALESCE(categories.name, 'Tanpa kategori') AS name, SUM(transactions.amount) AS amount, COUNT(*) AS count")
            ->orderByDesc('amount')->get()->map(fn ($category) => [
                'id' => $category->category_id, 'name' => $category->name, 'amount' => (float) $category->amount,
                'count' => (int) $category->count,
                'percentage' => $totals->expense > 0 ? round($category->amount / $totals->expense * 100, 1) : 0,
            ]);
        $transactions = (clone $expenses)->with(['account', 'category', 'relatedAccount'])->orderByDesc('date')->orderByDesc('id')->paginate(20);
        $days = (int) $start->startOfDay()->diffInDays($end->startOfDay()) + 1;
        $bucket = $days > 62 ? 'SUBSTR(date, 1, 7)' : 'date';
        $trend = (clone $base)->whereIn('type', ['income', 'expense'])->selectRaw("{$bucket} AS period, SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense, SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income")
            ->groupByRaw($bucket)->orderBy('period')->get()->map(fn ($row) => ['date' => substr($row->period, 0, 10), 'expense' => (float) $row->expense, 'income' => (float) $row->income]);
        $previous = $monthly ? (float) Transaction::where('user_id', $request->user()->id)->where('type', 'expense')
            ->whereBetween('date', [$start->subMonth()->toDateString(), $start->subDay()->toDateString()])->sum('amount') : null;

        return response()->json(['data' => [
            'from' => $start->toDateString(), 'to' => $end->toDateString(), 'days' => $days,
            'expense' => (float) $totals->expense, 'income' => (float) $totals->income,
            'net' => (float) $totals->income - (float) $totals->expense,
            'count' => $count, 'daily_average' => round($totals->expense / $days, 2),
            'trend' => $trend, 'trend_interval' => $days > 62 ? 'month' : 'day',
            'previous_expense' => $previous, 'categories' => $categories,
            'transactions' => TransactionResource::collection($transactions)->response()->getData(true),
        ]]);
    }
}
