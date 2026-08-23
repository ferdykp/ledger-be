<?php

namespace App\Http\Controllers;

use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TransactionController extends Controller
{
    public function __construct(
        protected TransactionService $transactionService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $limit = $request->integer('limit', 10);
        $transactions = $this->transactionService->getRecentTransactions($request->user(), $limit);

        return TransactionResource::collection($transactions);
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = $this->transactionService->createTransaction(
            $request->user(),
            $request->validated()
        );

        return response()->json([
            'message' => 'Transaksi berhasil dicatat.',
            'data' => new TransactionResource($transaction->load(['account', 'category'])),
        ], 201);
    }
}
