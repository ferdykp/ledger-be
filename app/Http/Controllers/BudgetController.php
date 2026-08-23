<?php

namespace App\Http\Controllers;

use App\Http\Requests\Budget\StoreBudgetRequest;
use App\Http\Resources\BudgetResource;
use App\Models\Budget;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class BudgetController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $month = $request->query('month', now()->format('Y-m'));
        $startDate = Carbon::parse($month . '-01')->startOfMonth();

        $budgets = Budget::with('category')
            ->where('user_id', $request->user()->id)
            ->whereDate('start_date', $startDate)
            ->get();

        return BudgetResource::collection($budgets);
    }

    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $startDate = Carbon::parse($validated['start_date'])->startOfMonth()->format('Y-m-d');

        // Upsert budget jika sudah pernah diset untuk kategori & bulan yang sama
        $budget = Budget::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'category_id' => $validated['category_id'],
                'period' => $validated['period'] ?? 'monthly',
                'start_date' => $startDate,
            ],
            [
                'amount_limit' => $validated['amount_limit'],
            ]
        );

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
