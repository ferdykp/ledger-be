<?php

namespace App\Http\Controllers;

use App\Http\Requests\Asset\AssetStatusRequest;
use App\Http\Requests\Asset\CompleteRdpuUnitsRequest;
use App\Http\Requests\Asset\AssetRefundRequest;
use App\Http\Requests\Asset\StoreAssetPriceRequest;
use App\Http\Requests\Asset\StoreAssetRequest;
use App\Http\Requests\Asset\StoreAssetTransactionRequest;
use App\Models\Asset;
use App\Models\AssetTransaction;
use App\Services\AssetService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    public function index(Request $request, AssetService $service)
    {
        return response()->json(['data' => $service->assets($request->user())]);
    }

    public function store(StoreAssetRequest $request, AssetService $service)
    {
        $asset = $service->create($request->user(), $request->validated());
        return response()->json(['data' => $service->snapshot($asset)], 201);
    }

    public function show(Request $request, Asset $asset, AssetService $service)
    {
        abort_unless($asset->user_id === $request->user()->id, 404);
        return response()->json(['data' => $service->snapshot($asset)]);
    }

    public function update(Request $request, Asset $asset, AssetService $service)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'provider' => ['nullable', 'string', 'max:160'],
            'identifier' => ['nullable', 'string', 'max:100'],
            'storage_type' => ['nullable', Rule::in(['physical', 'digital'])],
        ]);
        $updated = $service->updateMetadata($request->user(), $asset, $data);
        return response()->json(['data' => $service->snapshot($updated)]);
    }

    public function destroy(Request $request, Asset $asset, AssetService $service)
    {
        $service->delete($request->user(), $asset);
        return response()->json(['message' => 'Aset dan transaksi terkait berhasil dihapus. Saldo rekening sudah disesuaikan.']);
    }

    public function storeTransaction(StoreAssetTransactionRequest $request, Asset $asset, AssetService $service)
    {
        $transaction = $service->recordTransaction($request->user(), $asset, $request->validated());
        return response()->json(['data' => $transaction], 201);
    }

    public function transition(AssetStatusRequest $request, AssetTransaction $transaction, AssetService $service)
    {
        return response()->json(['data' => $service->transition($request->user(), $transaction, $request->validated())]);
    }

    public function refund(AssetRefundRequest $request, AssetTransaction $transaction, AssetService $service)
    {
        return response()->json(['data' => $service->recordRefund($request->user(), $transaction, $request->validated())], 201);
    }

    public function setPrice(StoreAssetPriceRequest $request, Asset $asset, AssetService $service)
    {
        $price = $service->setPrice($request->user(), $asset, $request->validated());
        return response()->json(['data' => $price]);
    }

    public function completeRdpuUnits(CompleteRdpuUnitsRequest $request, Asset $asset, AssetService $service)
    {
        $updated = $service->completeRdpuUnits($request->user(), $asset, $request->validated());
        return response()->json(['data' => $service->snapshot($updated)]);
    }

    public function importHistory(Request $request, Asset $asset, AssetService $service)
    {
        abort_unless($asset->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'idempotency_key' => ['nullable', 'string', 'max:80'],
            'reconcile_opening' => ['nullable', 'boolean'],
            'transactions' => ['required', 'array', 'min:1', 'max:100'],
            'transactions.*.transaction_date' => ['required', 'date'],
            'transactions.*.action' => ['nullable', Rule::in(['purchase', 'sale'])],
            'transactions.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
            'transactions.*.quantity' => ['required', 'numeric', 'gt:0', 'decimal:0,8', 'max:999999999.99999999'],
            'transactions.*.unit_price' => ['nullable', 'numeric', 'gt:0', 'decimal:0,8'],
            'transactions.*.fees' => ['nullable', 'numeric', 'gte:0', 'decimal:0,2'],
            'transactions.*.notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $result = $service->importHistory($request->user(), $asset, $data);
        return response()->json(['data' => $result], 201);
    }

    public function savingConsistency(Request $request)
    {
        $user = $request->user();
        $now = now();
        $purchases = AssetTransaction::query()->where('asset_transactions.user_id', $user->id)
            ->whereNull('asset_transactions.superseded_at')
            ->where('origin', '!=', 'opening')
            ->where(function ($query) {
                $query->where(fn ($q) => $q->where('type', 'purchase')->where('status', 'settled'))
                    ->orWhere(fn ($q) => $q->where('type', 'subscription')->where('status', 'confirmed'));
            });
        $activeMonths = (clone $purchases)->pluck('transaction_date')->map(fn ($date) => \Carbon\Carbon::parse($date)->format('Y-m'))->unique()->values()->all();
        $active = array_fill_keys($activeMonths, true);
        $streak = 0;
        $cursor = $now->copy()->startOfMonth();
        if (! isset($active[$cursor->format('Y-m')])) $cursor->subMonth(); // Current month stays open until month-end.
        while (isset($active[$cursor->format('Y-m')])) {
            $streak++;
            $cursor->subMonth();
        }
        $longest = 0;
        $run = 0;
        $months = collect($activeMonths)->sort()->values();
        $previous = null;
        foreach ($months as $month) {
            $date = \Carbon\Carbon::createFromFormat('Y-m', $month)->startOfMonth();
            $run = $previous && $previous->copy()->addMonth()->equalTo($date) ? $run + 1 : 1;
            $longest = max($longest, $run);
            $previous = $date;
        }
        $monthlyTotals = DB::table('asset_transactions')->join('assets', 'assets.id', '=', 'asset_transactions.asset_id')
            ->where('asset_transactions.user_id', $user->id)->whereNull('asset_transactions.superseded_at')->where('asset_transactions.origin', '!=', 'opening')
            ->where(function ($query) {
                $query->where(fn ($q) => $q->where('asset_transactions.type', 'purchase')->where('asset_transactions.status', 'settled'))
                    ->orWhere(fn ($q) => $q->where('asset_transactions.type', 'subscription')->where('asset_transactions.status', 'confirmed'));
            })
            ->whereBetween('asset_transactions.transaction_date', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])
            ->select('assets.type', DB::raw('SUM(asset_transactions.amount) as total_amount'))
            ->groupBy('assets.type')->get()->keyBy('type');
        $targets = collect(['gold', 'rdpu'])->map(function ($type) use ($user, $monthlyTotals) {
            $target = DB::table('asset_monthly_targets')->where('user_id', $user->id)->where('asset_type', $type)->value('amount');
            $actual = bcadd((string) ($monthlyTotals->get($type)?->total_amount ?? '0.00'), '0', 2);
            $normalizedTarget = $target === null ? null : bcadd((string) $target, '0', 2);
            return ['type' => $type, 'target' => $normalizedTarget, 'actual' => $actual,
                'remaining' => $normalizedTarget === null || bccomp($normalizedTarget, $actual, 2) <= 0 ? '0.00' : bcsub($normalizedTarget, $actual, 2)];
        });
        return response()->json(['data' => ['current_streak' => $streak, 'longest_streak' => $longest, 'active_months' => count($activeMonths), 'monthly_targets' => $targets]]);
    }

    public function setMonthlyTarget(Request $request)
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(['gold', 'rdpu'])],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999999.99'],
        ]);
        DB::table('asset_monthly_targets')->updateOrInsert(
            ['user_id' => $request->user()->id, 'asset_type' => $data['type']],
            ['amount' => $data['amount'], 'updated_at' => now(), 'created_at' => now()],
        );
        return response()->json(['message' => 'Target investasi bulanan disimpan.']);
    }

    public function deleteMonthlyTarget(Request $request)
    {
        $data = $request->validate(['type' => ['required', Rule::in(['gold', 'rdpu'])]]);
        $deleted = DB::table('asset_monthly_targets')->where('user_id', $request->user()->id)->where('asset_type', $data['type'])->delete();
        abort_unless($deleted, 404, 'Target bulanan tidak ditemukan.');
        return response()->json(['message' => 'Target investasi bulanan dihapus.']);
    }
}
