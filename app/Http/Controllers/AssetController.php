<?php

namespace App\Http\Controllers;

use App\Http\Requests\Asset\AssetStatusRequest;
use App\Http\Requests\Asset\AssetRefundRequest;
use App\Http\Requests\Asset\StoreAssetPriceRequest;
use App\Http\Requests\Asset\StoreAssetRequest;
use App\Http\Requests\Asset\StoreAssetTransactionRequest;
use App\Models\Asset;
use App\Models\AssetTransaction;
use App\Services\AssetService;
use Illuminate\Http\Request;

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
}
