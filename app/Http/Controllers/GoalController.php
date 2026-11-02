<?php

namespace App\Http\Controllers;

use App\Http\Requests\Goal\AddContributionRequest;
use App\Http\Requests\Goal\StoreGoalRequest;
use App\Http\Resources\GoalContributionResource;
use App\Http\Resources\GoalResource;
use App\Models\Goal;
use App\Models\GoalContribution;
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

    public function updateContribution(AddContributionRequest $request, Goal $goal, GoalContribution $contribution): JsonResponse
    {
        $this->authorizeOwner($request->user()->id, $goal);
        abort_unless($contribution->goal_id === $goal->id, 404);
        $data = $request->safe()->except('idempotency_key');
        $updatedGoal = DB::transaction(function () use ($request, $goal, $contribution, $data) {
            $lockedGoal = Goal::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($goal->id);
            $lockedContribution = GoalContribution::where('goal_id', $lockedGoal->id)->lockForUpdate()->findOrFail($contribution->id);
            $delta = DecimalMath::subtract((string) $data['amount'], (string) $lockedContribution->amount);
            $current = (string) $lockedGoal->current_amount;
            if (DecimalMath::compare($delta, '0.00') < 0 && DecimalMath::compare($current, DecimalMath::subtract('0.00', $delta)) < 0) {
                abort(409, 'Saldo target tidak cukup untuk mengurangi nominal setoran tersebut.');
            }
            $lockedContribution->update($data);
            $lockedGoal->current_amount = DecimalMath::add($current, $delta);
            $lockedGoal->save();
            return $lockedGoal->fresh()->load(['items.obligations.payments']);
        }, 3);
        return response()->json(['data' => new GoalResource($updatedGoal)]);
    }

    public function deleteContribution(Request $request, Goal $goal, GoalContribution $contribution): JsonResponse
    {
        $this->authorizeOwner($request->user()->id, $goal);
        abort_unless($contribution->goal_id === $goal->id, 404);
        $updatedGoal = DB::transaction(function () use ($request, $goal, $contribution) {
            $lockedGoal = Goal::where('user_id', $request->user()->id)->lockForUpdate()->findOrFail($goal->id);
            $lockedContribution = GoalContribution::where('goal_id', $lockedGoal->id)->lockForUpdate()->findOrFail($contribution->id);
            abort_if(DecimalMath::compare((string) $lockedGoal->current_amount, (string) $lockedContribution->amount) < 0, 409, 'Saldo target tidak cukup untuk menghapus catatan setoran ini.');
            $lockedGoal->current_amount = DecimalMath::subtract((string) $lockedGoal->current_amount, (string) $lockedContribution->amount);
            $lockedGoal->save();
            $lockedContribution->delete();
            return $lockedGoal->fresh()->load(['items.obligations.payments']);
        }, 3);
        return response()->json(['data' => new GoalResource($updatedGoal)]);
    }

    private function authorizeOwner(int $userId, Goal $goal): void
    {
        if ($goal->user_id !== $userId) {
            abort(403, 'Akses ditolak.');
        }
    }
}
