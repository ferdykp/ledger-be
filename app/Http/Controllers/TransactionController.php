<?php

namespace App\Http\Controllers;

use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Models\Transaction;
use App\Models\Account;
use Illuminate\Support\Facades\DB;
use Exception;

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
    public function destroy(Transaction $transaction)
    {
        // Pastikan transaksi milik user yang sedang login
        if ($transaction->user_id !== auth()->id()) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        try {
            DB::transaction(function () use ($transaction) {
                // 1. Revert Saldo Akun Utamanya (jika ada)
                if ($transaction->account_id) {
                    $account = Account::find($transaction->account_id);
                    if ($account) {
                        if ($transaction->type === 'expense') {
                            // Pengeluaran dihapus = Saldo bertambah kembali
                            $account->increment('balance', $transaction->amount);
                        } elseif ($transaction->type === 'income') {
                            // Pemasukan dihapus = Saldo berkurang kembali
                            $account->decrement('balance', $transaction->amount);
                        } elseif ($transaction->type === 'transfer') {
                            // Transfer dihapus = Saldo pengirim dikembalikan
                            $account->increment('balance', $transaction->amount);
                        }
                    }
                }

                // 2. Revert Saldo Akun Tujuan (Khusus Transfer)
                if ($transaction->type === 'transfer' && $transaction->to_account_id) {
                    $toAccount = Account::find($transaction->to_account_id);
                    if ($toAccount) {
                        $toAccount->decrement('balance', $transaction->amount);
                    }
                }

                // 3. Hapus Transaksi dari Database
                $transaction->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Transaksi berhasil dihapus dan saldo diperbarui.'
            ], 200);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus transaksi: ' . $e->getMessage()
            ], 500);
        }
    }
}
