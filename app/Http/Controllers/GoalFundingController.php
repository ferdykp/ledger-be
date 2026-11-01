<?php

namespace App\Http\Controllers;

use App\Http\Requests\Asset\AssetAllocationRequest;
use App\Models\Asset;
use App\Models\Goal;
use App\Models\GoalAssetAllocation;
use App\Services\AssetService;
use App\Services\GoalFinancialSummaryService;
use Illuminate\Http\Request;

class GoalFundingController extends Controller
{
    public function all(Request $request, GoalFinancialSummaryService $service)
    {
        $rows = Goal::where('user_id', $request->user()->id)
            ->with(['items.obligations.payments'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Goal $goal) => $service->forGoal($goal));

        return response()->json(['data' => $rows]);
    }

    public function summary(Request $request, Goal $goal, GoalFinancialSummaryService $service)
    {
        abort_unless($goal->user_id === $request->user()->id, 404);
        return response()->json(['data' => $service->forGoal($goal)]);
    }

    public function assets(Request $request, Goal $goal)
    {
        abort_unless($goal->user_id === $request->user()->id, 404);
        $rows = GoalAssetAllocation::where('goal_id', $goal->id)->with('asset')->latest()->paginate(50);
        return response()->json($rows);
    }

    public function allocateAsset(AssetAllocationRequest $request, Goal $goal, Asset $asset, AssetService $service)
    {
        $entry = $service->allocate($request->user(), $goal, $asset, $request->validated());
        return response()->json(['data' => $entry], 201);
    }
}
