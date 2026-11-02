<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ExpenseRecapController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\GoalItemController;
use App\Http\Controllers\GoalObligationController;
use App\Http\Controllers\GoalCashAllocationController;
use App\Http\Controllers\GoalFundingController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\SharedFinancialWorkspaceController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\NetWorthController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use App\Http\Controllers\WhatsAppIntegrationController;
use App\Http\Controllers\WhatsAppVerificationController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::post('/forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:5,1');

// Route::get('/ping', fn() => response()->json(['message' => 'pong']))->middleware('auth:sanctum');
Route::post('/webhooks/whatsapp', WhatsAppWebhookController::class)->middleware('throttle:120,1');

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/integrations/whatsapp', [WhatsAppIntegrationController::class, 'status']);
    Route::post('/integrations/whatsapp/pairing', [WhatsAppIntegrationController::class, 'pairing'])->middleware('throttle:5,1');
    Route::delete('/integrations/whatsapp', [WhatsAppIntegrationController::class, 'disconnect']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn (Request $request) => $request->user());
    Route::post('user/profile', [ProfileController::class, 'update']);
    Route::put('user/password', [PasswordController::class, 'change'])->middleware('throttle:5,1');
    Route::apiResource('accounts', AccountController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::get('reports/expenses', ExpenseRecapController::class);
    Route::get('reports/monthly', [TransactionController::class, 'report']);
    Route::get('reports/cash-flow', [TransactionController::class, 'cashFlow']);
    Route::get('reports/net-worth', NetWorthController::class);
    Route::get('transactions/export', [TransactionController::class, 'export']);
    Route::apiResource('transactions', TransactionController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::post('imports/scan', [ImportController::class, 'scan'])->middleware('throttle:20,1');
    Route::apiResource('budgets', BudgetController::class)->only(['index', 'store', 'destroy']);
    Route::apiResource('bills', BillController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('bills/{bill}/paid', [BillController::class, 'markPaid']);
    Route::get('goals/funding-summary', [GoalFundingController::class, 'all']);
    Route::apiResource('goals', GoalController::class);
    Route::get('goals/{goal}/contributions', [GoalController::class, 'contributions']);
    Route::post('goals/{goal}/contributions', [GoalController::class, 'addContribution']);
    Route::get('goals/{goal}/items', [GoalItemController::class, 'index']);
    Route::post('goals/{goal}/items', [GoalItemController::class, 'store']);
    Route::put('goals/{goal}/items/{item}', [GoalItemController::class, 'update']);
    Route::delete('goals/{goal}/items/{item}', [GoalItemController::class, 'destroy']);
    Route::get('goals/{goal}/items/{item}/obligations', [GoalObligationController::class, 'index']);
    Route::post('goals/{goal}/items/{item}/obligations', [GoalObligationController::class, 'store']);
    Route::put('goals/{goal}/items/{item}/obligations/{obligation}', [GoalObligationController::class, 'update']);
    Route::delete('goals/{goal}/items/{item}/obligations/{obligation}', [GoalObligationController::class, 'destroy']);
    Route::get('goals/{goal}/cash-allocations', [GoalCashAllocationController::class, 'index']);
    Route::post('goals/{goal}/cash-allocations', [GoalCashAllocationController::class, 'store']);
    Route::get('cash-allocations/accounts', [GoalCashAllocationController::class, 'accounts']);
    Route::get('goals/{goal}/funding', [GoalFundingController::class, 'summary']);
    Route::get('goals/{goal}/asset-allocations', [GoalFundingController::class, 'assets']);
    Route::post('goals/{goal}/assets/{asset}/allocations', [GoalFundingController::class, 'allocateAsset']);
    Route::get('assets/saving-consistency', [AssetController::class, 'savingConsistency']);
    Route::put('assets/saving-consistency/target', [AssetController::class, 'setMonthlyTarget']);
    Route::apiResource('assets', AssetController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::post('assets/{asset}/transactions', [AssetController::class, 'storeTransaction']);
    Route::post('assets/{asset}/complete-units', [AssetController::class, 'completeRdpuUnits']);
    Route::post('assets/{asset}/transactions/import', [AssetController::class, 'importHistory']);
    Route::post('assets/transactions/{transaction}/status', [AssetController::class, 'transition']);
    Route::post('assets/transactions/{transaction}/refunds', [AssetController::class, 'refund']);
    Route::post('assets/{asset}/prices', [AssetController::class, 'setPrice']);

    Route::get('shared-workspaces/invitations/pending', [SharedFinancialWorkspaceController::class, 'pendingInvitations']);
    Route::post('shared-workspaces/invitations/accept', [SharedFinancialWorkspaceController::class, 'acceptInvitation']);
    Route::post('shared-workspaces/invitations/{invitation}/accept', [SharedFinancialWorkspaceController::class, 'acceptPendingInvitation']);
    Route::get('shared-workspaces', [SharedFinancialWorkspaceController::class, 'index']);
    Route::post('shared-workspaces', [SharedFinancialWorkspaceController::class, 'store']);
    Route::post('shared-workspaces/{workspace}/invitations', [SharedFinancialWorkspaceController::class, 'invite']);
    Route::delete('shared-workspaces/{workspace}/invitations/{invitation}', [SharedFinancialWorkspaceController::class, 'revokeInvitation']);
    Route::post('shared-workspaces/{workspace}/contributions', [SharedFinancialWorkspaceController::class, 'contribute']);
    Route::post('shared-workspaces/{workspace}/withdrawals', [SharedFinancialWorkspaceController::class, 'withdraw']);
    Route::post('shared-workspaces/{workspace}/assets', [SharedFinancialWorkspaceController::class, 'createAsset']);
    Route::post('shared-workspaces/{workspace}/goals', [SharedFinancialWorkspaceController::class, 'createGoal']);
    Route::post('shared-workspaces/{workspace}/goals/{goal}/allocations', [SharedFinancialWorkspaceController::class, 'allocateGoal']);
    Route::post('shared-workspaces/{workspace}/assets/{asset}/transactions', [SharedFinancialWorkspaceController::class, 'transactAsset']);
    Route::delete('shared-workspaces/{workspace}/members/{member}', [SharedFinancialWorkspaceController::class, 'removeMember']);
    Route::get('shared-workspaces/{workspace}', [SharedFinancialWorkspaceController::class, 'show']);

    Route::post(
        '/integrations/whatsapp/otp/send',
        [WhatsAppVerificationController::class, 'send']
    )->middleware('throttle:5,60');

    Route::post(
        '/integrations/whatsapp/otp/verify',
        [WhatsAppVerificationController::class, 'verify']
    )->middleware('throttle:10,1');
});
