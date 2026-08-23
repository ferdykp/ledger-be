<?php

namespace App\Http\Controllers;

use App\Http\Requests\Goal\AddContributionRequest;
use App\Http\Requests\Goal\StoreGoalRequest;
use App\Http\Resources\GoalResource;
use App\Models\Goal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class GoalController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $goals = Goal::where('user_id', $request->user()->id)
            ->with(['contributions' => fn($q) => $q->orderBy('date', 'desc')])
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
            'target_date' => $validated['target_date'] ?? null,
            'icon' => $validated['icon'] ?? 'plane',
        ]);

        return response()->json([
            'message' => 'Impian tabungan berhasil dibuat.',
            'data' => new GoalResource($goal),
        ], 201);
    }

    public function show(Request $request, Goal $goal): JsonResponse
    {
        $this->authorizeOwner($request->user()->id, $goal);

        return response()->json([
            'data' => new GoalResource($goal->load(['contributions' => fn($q) => $q->orderBy('date', 'desc')])),
        ]);
    }

    public function update(StoreGoalRequest $request, Goal $goal): JsonResponse
    {
        $this->authorizeOwner($request->user()->id, $goal);

        $goal->update($request->validated());

        return response()->json([
            'message' => 'Impian tabungan berhasil diperbarui.',
            'data' => new GoalResource($goal),
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

        DB::transaction(function () use ($goal, $validated) {
            $goal->contributions()->create([
                'amount' => $validated['amount'],
                'date' => $validated['date'],
            ]);

            $goal->increment('current_amount', $validated['amount']);
        });

        return response()->json([
            'message' => 'Berhasil menambah tabungan!',
            'data' => new GoalResource($goal->fresh(['contributions'])),
        ]);
    }

    private function authorizeOwner(int $userId, Goal $goal): void
    {
        if ($goal->user_id !== $userId) {
            abort(403, 'Akses ditolak.');
        }
    }
}
