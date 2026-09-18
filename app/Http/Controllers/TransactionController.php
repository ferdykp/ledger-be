<?php
namespace App\Http\Controllers;

use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Http\Requests\Transaction\UpdateTransactionRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function __construct(protected TransactionService $transactionService) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'month'=>['nullable','date_format:Y-m'],'from'=>['nullable','date'],'to'=>['nullable','date'],
            'type'=>['nullable','in:all,income,expense,transfer'],'account_id'=>['nullable'],'category_id'=>['nullable'],
            'search'=>['nullable','string','max:100'],'limit'=>['nullable','integer','min:1','max:500'],
        ]);
        $limit = (int)($filters['limit'] ?? 100);
        return TransactionResource::collection($this->transactionService->query($request->user(), $filters)->limit($limit)->get());
    }

    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $transaction = $this->transactionService->createTransaction($request->user(), $request->validated());
        return response()->json(['message'=>'Transaksi berhasil dicatat.','data'=>new TransactionResource($transaction)],201);
    }

    public function show(Request $request, Transaction $transaction): TransactionResource
    { abort_unless($transaction->user_id === $request->user()->id,403); return new TransactionResource($transaction->load(['account','relatedAccount','category'])); }

    public function update(UpdateTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $transaction = $this->transactionService->updateTransaction($request->user(), $transaction, $request->validated());
        return response()->json(['message'=>'Transaksi berhasil diperbarui.','data'=>new TransactionResource($transaction)]);
    }

    public function destroy(Request $request, Transaction $transaction): JsonResponse
    { $this->transactionService->deleteTransaction($request->user(), $transaction); return response()->json(['success'=>true,'message'=>'Transaksi berhasil dihapus dan saldo diperbarui.']); }

    public function report(Request $request): JsonResponse
    { $data=$request->validate(['month'=>['required','date_format:Y-m']]); return response()->json(['data'=>$this->transactionService->report($request->user(),$data['month'])]); }

    public function cashFlow(Request $request): JsonResponse
    { $months=max(1,min($request->integer('months',6),24)); return response()->json(['data'=>$this->transactionService->cashFlow($request->user(),$months)]); }
}
