<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [PasswordController::class, 'forgot'])->middleware('throttle:5,1');
Route::post('/reset-password', [PasswordController::class, 'reset'])->middleware('throttle:5,1');

// Route::get('/ping', fn() => response()->json(['message' => 'pong']))->middleware('auth:sanctum');
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn(Request $request) => $request->user());
    Route::post('user/profile', [ProfileController::class, 'update']);
    Route::put('user/password', [PasswordController::class, 'change'])->middleware('throttle:5,1');
    Route::apiResource('accounts', AccountController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::get('reports/monthly', [TransactionController::class, 'report']);
    Route::get('reports/cash-flow', [TransactionController::class, 'cashFlow']);
    Route::apiResource('transactions', TransactionController::class)->only(['index', 'store', 'show', 'update', 'destroy']);
    Route::apiResource('budgets', BudgetController::class)->only(['index', 'store', 'destroy']);
    Route::apiResource('goals', GoalController::class);
    Route::post('goals/{goal}/contributions', [GoalController::class, 'addContribution']);
});
