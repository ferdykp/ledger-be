<?php

namespace App\Http\Controllers;

use App\Http\Requests\Goal\StoreGoalObligationRequest;
use App\Http\Resources\GoalObligationResource;
use App\Models\Goal;
use App\Models\GoalItem;
use App\Models\GoalObligation;
use Illuminate\Http\Request;

class GoalObligationController extends Controller
{
    public function index(Request $request, Goal $goal, GoalItem $item)
    {
        $this->authorizeItem($request, $goal, $item);
        return GoalObligationResource::collection($item->obligations()->with('payments')->orderBy('due_date')->get());
    }

    public function store(StoreGoalObligationRequest $request, Goal $goal, GoalItem $item)
    {
        $this->authorizeItem($request, $goal, $item);
        $obligation = $item->obligations()->create($request->validated());
        return response()->json(['data' => new GoalObligationResource($obligation)], 201);
    }

    public function update(StoreGoalObligationRequest $request, Goal $goal, GoalItem $item, GoalObligation $obligation)
    {
        $this->authorizeObligation($request, $goal, $item, $obligation);
        $obligation->update($request->validated());
        return response()->json(['data' => new GoalObligationResource($obligation->fresh()->load('payments'))]);
    }

    public function destroy(Request $request, Goal $goal, GoalItem $item, GoalObligation $obligation)
    {
        $this->authorizeObligation($request, $goal, $item, $obligation);
        abort_if($obligation->payments()->exists(), 409, 'Kewajiban yang memiliki transaksi pembayaran tidak dapat dihapus.');
        $obligation->delete();
        return response()->json(['message' => 'Jadwal pembayaran dihapus.']);
    }

    private function authorizeItem(Request $request, Goal $goal, GoalItem $item): void
    {
        abort_unless($goal->user_id === $request->user()->id && $item->goal_id === $goal->id, 404);
    }

    private function authorizeObligation(Request $request, Goal $goal, GoalItem $item, GoalObligation $obligation): void
    {
        $this->authorizeItem($request, $goal, $item);
        abort_unless($obligation->goal_item_id === $item->id, 404);
    }
}
