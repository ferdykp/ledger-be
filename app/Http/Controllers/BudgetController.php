<?php

namespace App\Http\Controllers;

use App\Http\Requests\Budget\StoreBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class BudgetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = $request->query('month', now()->format('Y-m'));
        $startDate = Carbon::parse($month.'-01')->startOfMonth();

        $budgets = Budget::with('category')
            ->where('user_id', $request->user()->id)
            ->where(function ($query) use ($startDate) {
                $query->where(function ($monthly) use ($startDate) {
                    $monthly->where('period', 'monthly')->whereDate('start_date', $startDate);
                })->orWhere(function ($yearly) use ($startDate) {
                    $yearly->where('period', 'yearly')->whereDate('start_date', '<=', $startDate)
                        ->whereDate('start_date', '>', $startDate->copy()->subYear());
                });
            })
            ->get();

        // One aggregate per distinct budget period, rather than one query per category.
        foreach ($budgets->groupBy(fn ($budget) => $budget->period.':'.$budget->start_date->format('Y-m-d')) as $group) {
            $first = $group->first();
            $start = $first->start_date->copy()->startOfMonth();
            $end = $first->period === 'yearly' ? $start->copy()->addYear()->subDay() : $start->copy()->endOfMonth();
            $spent = Transaction::where('user_id', $request->user()->id)->where('type', 'expense')
                ->whereIn('category_id', $group->pluck('category_id'))
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->groupBy('category_id')->selectRaw('category_id, SUM(amount) as total')->pluck('total', 'category_id');
            foreach ($group as $budget) $budget->setAttribute('spent_total', $spent[$budget->category_id] ?? 0);
        }

        return BudgetResource::collection($budgets);
    }

    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $startDate = Carbon::parse($validated['start_date'])->startOfMonth()->format('Y-m-d');

        $budget = DB::transaction(function () use ($request, $validated, $startDate) {
            // Serialize upserts for this owner; date casts may include midnight in SQLite.
            $request->user()->newQuery()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $identity = [
                'user_id' => $request->user()->id,
                'category_id' => $validated['category_id'],
                'period' => $validated['period'] ?? 'monthly',
            ];
            $budget = Budget::where($identity)->whereDate('start_date', $startDate)->first();
            if ($budget) {
                $budget->update(['amount_limit' => $validated['amount_limit']]);

                return $budget;
            }

            return Budget::create([...$identity, 'start_date' => $startDate, 'amount_limit' => $validated['amount_limit']]);
        });

        return response()->json([
            'message' => 'Budget berhasil disimpan.',
            'data' => new BudgetResource($budget->load('category')),
        ], 201);
    }

    public function destroy(Request $request, Budget $budget): JsonResponse
    {
        if ($budget->user_id !== $request->user()->id) {
            abort(403, 'Akses ditolak.');
        }

        $budget->delete();

        return response()->json(['message' => 'Budget berhasil dihapus.']);
    }
}
