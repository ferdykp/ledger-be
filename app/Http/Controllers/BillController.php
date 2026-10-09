<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Services\BillService;
use Illuminate\Http\Request;

class BillController extends Controller
{
    public function __construct(private BillService $bills) {}

    private function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'amount' => 'required|numeric|min:0|decimal:0,2|max:9999999999999.99',
            'due_date' => 'required|date_format:Y-m-d',
            'frequency' => 'required|in:once,weekly,monthly,yearly',
            'status' => 'sometimes|required|in:active,paid,paused',
            'category' => 'nullable|string|max:80',
            'note' => 'nullable|string|max:1000',
            'reminder_enabled' => 'sometimes|boolean',
        ];
    }

    public function index(Request $request)
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'today' => ['sometimes', 'date_format:Y-m-d'],
        ]);
        $today = $data['today'] ?? now()->toDateString();
        $active = $request->user()->bills()->where('status', 'active');
        $summary = (clone $active)->selectRaw("COUNT(*) AS active_count, COALESCE(SUM(amount * CASE frequency WHEN 'yearly' THEN 1.0/12 WHEN 'weekly' THEN 52.0/12 WHEN 'monthly' THEN 1 ELSE 0 END), 0) AS monthly_total")->first();
        $overdue = (clone $active)->where('reminder_enabled', true)->whereDate('due_date', '<=', $today)->count();
        $page = $request->user()->bills()->orderBy('due_date')->orderBy('id')->paginate(20);

        return response()->json([...$page->toArray(), 'summary' => [
            'active_count' => (int) $summary->active_count,
            'monthly_total' => (float) $summary->monthly_total,
            'overdue_count' => $overdue,
        ]]);
    }

    public function store(Request $request)
    {
        return response()->json(['data' => $this->bills->save($request->user(), $request->validate($this->rules()))], 201);
    }

    public function update(Request $request, Bill $bill)
    {
        abort_unless($bill->user_id === $request->user()->id, 403);
        $bill = $this->bills->save($request->user(), $request->validate($this->rules()), $bill);

        return response()->json(['data' => $bill->fresh()]);
    }

    public function destroy(Request $request, Bill $bill)
    {
        abort_unless($bill->user_id === $request->user()->id, 403);
        $bill->delete();

        return response()->noContent();
    }

    public function markPaid(Request $request, Bill $bill)
    {
        abort_unless($bill->user_id === $request->user()->id, 403);
        $bill = $this->bills->save($request->user(), ['status' => 'paid'], $bill);

        return response()->json(['data' => $bill]);
    }
}
