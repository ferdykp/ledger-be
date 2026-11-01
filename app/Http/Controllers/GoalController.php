<?php

namespace App\Http\Controllers;

use App\Http\Requests\Goal\AddContributionRequest;
use App\Http\Requests\Goal\StoreGoalRequest;
use App\Http\Resources\GoalContributionResource;
use App\Http\Resources\GoalResource;
use App\Models\Goal;
use App\Support\DecimalMath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class GoalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $goals = Goal::where('user_id', $request->user()->id)->with(['items.obligations.payments'])
            ->orderBy('created_at', 'desc')
            ->get();

        return GoalResource::collection($goals);
    }

    public function store(StoreGoalRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $goal = $request->user()->goals()->create([
            'name' => $validated['name'],
            'target_amount' => $validated['target_amount'],
            'current_amount' => $validated['current_amount'] ?? 0,
            'opening_balance' => $validated['current_amount'] ?? 0,
            'target_date' => $validated['target_date'] ?? null,
            'icon' => $validated['icon'] ?? 'plane',
        ]);

        return response()->json([
            'message' => 'Impian tabungan berhasil dibuat.',
            'data' => new GoalResource($goal->load(['items.obligations'])),
        ], 201);
    }

    public function show(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeOwner($request->user()->id, $goal);

        return response()->json([
            'data' => new GoalResource($goal->load(['items.obligations', 'contributions'])),
        ]);
    }

    public function contributions(Request $request, Goal $goal): AnonymousResourceCollection
    {
        $this->authorizeOwner($request->user()->id, $goal);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);

        return GoalContributionResource::collection(
            $goal->contributions()->orderByDesc('date')->orderByDesc('id')->paginate(20)
        );
    }

    public function update(StoreGoalRequest $request, Goal $goal): JsonResponse
    {
        $this->authorizeOwner($request->user()->id, $goal);

        $goal->update($request->safe()->except('current_amount'));

        return response()->json([
            'message' => 'Impian tabungan berhasil diperbarui.',
            'data' => new GoalResource($goal->load(['items.obligations.payments'])),
        ]);
    }

    public function destroy(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeOwner($request->user()->id, $goal);

        $goal->delete();

        return response()->json(['message' => 'Goal berhasil dihapus.']);
    }

    // Tambah kontribusi tabungan (Nabung)
    public function addContribution(AddContributionRequest $request, Goal $goal): JsonResponse
    {
        $this->authorizeOwner($request->user()->id, $goal);

        $validated = $request->validated();
        $created = DB::transaction(function () use ($request, $goal, $validated) {
            $goal = Goal::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($goal->id);
            if (! empty($validated['idempotency_key'])) {
                $existing = $goal->contributions()->where('idempotency_key', $validated['idempotency_key'])->first();
                if ($existing) {
                    abort_unless(DecimalMath::compare((string) $existing->amount, (string) $validated['amount']) === 0 && $existing->date->format('Y-m-d') === $validated['date'], 409, 'Kunci permintaan sudah digunakan untuk setoran yang berbeda.');
                    return false;
                }
            }
            $goal->contributions()->create([
                'amount' => $validated['amount'],
                'date' => $validated['date'],
                'note' => $validated['note'] ?? null,
                'idempotency_key' => $validated['idempotency_key'] ?? null,
            ]);
            $goal->increment('current_amount', $validated['amount']);
            return true;
        });

        return response()->json([
            'message' => $created ? 'Berhasil menambah tabungan!' : 'Setoran sudah tercatat.',
            'data' => new GoalResource($goal->fresh()->load(['items.obligations.payments'])),
        ]);
    }

    private function authorizeOwner(int $userId, Goal $goal): void
    {
        if ($goal->user_id !== $userId) {
            abort(403, 'Akses ditolak.');
        }
    }
}
