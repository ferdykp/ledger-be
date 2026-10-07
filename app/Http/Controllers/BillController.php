<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BillController extends Controller
{
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
        return response()->json(['data' => $request->user()->bills()->orderBy('due_date')->get()]);
    }

    public function store(Request $request)
    {
        return response()->json(['data' => $request->user()->bills()->create($request->validate($this->rules()))], 201);
    }

    public function update(Request $request, Bill $bill)
    {
        abort_unless($bill->user_id === $request->user()->id, 403);
        $bill->update($request->validate($this->rules()));

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
        $bill = DB::transaction(function () use ($bill) {
            $bill = Bill::lockForUpdate()->findOrFail($bill->id);
            if ($bill->status === 'paid') {
                return $bill;
            }
            $bill->update(['status' => 'paid']);
            $nextDate = match ($bill->frequency) {
                'weekly' => $bill->due_date->copy()->addWeek(),
                'monthly' => $bill->due_date->copy()->addMonthNoOverflow(),
                'yearly' => $bill->due_date->copy()->addYearNoOverflow(),
                default => null,
            };
            if ($nextDate) {
                $next = $bill->replicate();
                $next->fill(['status' => 'active', 'due_date' => $nextDate]);
                $next->save();
            }

            return $bill;
        });

        return response()->json(['data' => $bill]);
    }
}
