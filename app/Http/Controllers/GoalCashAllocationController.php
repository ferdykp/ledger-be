<?php

namespace App\Http\Controllers;

use App\Http\Requests\Goal\CashAllocationRequest;
use App\Models\Goal;
use App\Services\GoalCashAllocationService;
use Illuminate\Http\Request;

class GoalCashAllocationController extends Controller
{
    public function index(Request $request, GoalCashAllocationService $service)
    {
        abort_unless($goal = Goal::where('user_id', $request->user()->id)->find($request->route('goal')), 404);
        return response()->json(['data' => $service->summary($request->user()), 'history' => $goal->cashAllocations()->with('account')->latest()->paginate(50)]);
    }

    public function store(CashAllocationRequest $request, Goal $goal, GoalCashAllocationService $service)
    {
        $entry = $service->record($request->user(), $goal, $request->validated());
        return response()->json(['data' => $entry->load('account'), 'accounts' => $service->summary($request->user())], 201);
    }

    public function accounts(Request $request, GoalCashAllocationService $service)
    {
        return response()->json(['data' => $service->summary($request->user())]);
    }
}
