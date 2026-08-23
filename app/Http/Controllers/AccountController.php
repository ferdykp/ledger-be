<?php

namespace App\Http\Controllers;

use App\Http\Requests\Account\StoreAccountRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Services\AccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AccountController extends Controller
{
    public function __construct(
        protected AccountService $accountService
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $includeArchived = $request->boolean('include_archived', false);
        $accounts = $this->accountService->getUserAccounts($request->user(), $includeArchived);

        return AccountResource::collection($accounts);
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->accountService->createAccount($request->user(), $request->validated());

        return response()->json([
            'message' => 'Akun berhasil dibuat.',
            'data' => new AccountResource($account),
        ], 201);
    }

    public function show(Request $request, Account $account): JsonResponse
    {
        $this->authorizeOwner($request->user()->id, $account);

        return response()->json([
            'data' => new AccountResource($account),
        ]);
    }

    public function update(UpdateAccountRequest $request, Account $account): JsonResponse
    {
        $updatedAccount = $this->accountService->updateAccount($account, $request->validated());

        return response()->json([
            'message' => 'Akun berhasil diperbarui.',
            'data' => new AccountResource($updatedAccount),
        ]);
    }

    public function destroy(Request $request, Account $account): JsonResponse
    {
        $this->authorizeOwner($request->user()->id, $account);

        $this->accountService->deleteAccount($account);

        return response()->json([
            'message' => 'Akun berhasil dihapus.',
        ]);
    }

    private function authorizeOwner(int $userId, Account $account): void
    {
        if ($account->user_id !== $userId) {
            abort(403, 'Anda tidak memiliki akses ke akun ini.');
        }
    }
}
