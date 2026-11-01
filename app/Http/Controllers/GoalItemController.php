<?php

namespace App\Http\Controllers;

use App\Http\Requests\Goal\StoreGoalItemRequest;
use App\Http\Resources\GoalItemResource;
use App\Models\Goal;
use App\Models\GoalItem;
use App\Support\DecimalMath;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GoalItemController extends Controller
{
    public function index(Request $request, Goal $goal)
    {
        $this->authorizeGoal($request, $goal);
        return GoalItemResource::collection($goal->items()->with('obligations.payments')->get());
    }

    public function store(StoreGoalItemRequest $request, Goal $goal)
    {
        $this->authorizeGoal($request, $goal);
        $item = DB::transaction(function () use ($request, $goal) {
            $goal = Goal::whereKey($goal->id)->lockForUpdate()->firstOrFail();
            $item = $goal->items()->create($request->validated() + ['quantity' => '1.0000']);
            $this->syncBudget($goal);
            return $item;
        }, 3);
        return response()->json(['data' => new GoalItemResource($item)], 201);
    }

    public function update(StoreGoalItemRequest $request, Goal $goal, GoalItem $item)
    {
        $this->authorizeItem($request, $goal, $item);
        DB::transaction(function () use ($request, $goal, $item) {
            $goal = Goal::whereKey($goal->id)->lockForUpdate()->firstOrFail();
            $item->update($request->validated());
            $this->syncBudget($goal);
        }, 3);
        return response()->json(['data' => new GoalItemResource($item->fresh()->load('obligations.payments'))]);
    }

    public function destroy(Request $request, Goal $goal, GoalItem $item)
    {
        $this->authorizeItem($request, $goal, $item);
        abort_if($item->obligations()->whereHas('payments')->exists(), 409, 'Hapus atau pindahkan pembayaran terkait sebelum menghapus rincian ini.');
        DB::transaction(function () use ($goal, $item) {
            $goal = Goal::whereKey($goal->id)->lockForUpdate()->firstOrFail();
            $item->delete();
            $this->syncBudget($goal);
        }, 3);
        return response()->json(['message' => 'Rincian target dihapus.']);
    }

    private function syncBudget(Goal $goal): void
    {
        $total = '0.00';
        foreach ($goal->items()->get() as $item) $total = DecimalMath::add($total, $item->estimated_amount);
        $goal->update(['target_amount' => $total]);
    }

    private function authorizeGoal(Request $request, Goal $goal): void
    {
        abort_unless($goal->user_id === $request->user()->id, 403);
    }

    private function authorizeItem(Request $request, Goal $goal, GoalItem $item): void
    {
        $this->authorizeGoal($request, $goal);
        abort_unless($item->goal_id === $goal->id, 404);
    }
}
