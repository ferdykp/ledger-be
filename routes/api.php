<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TransactionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Route::get('/ping', fn() => response()->json(['message' => 'pong']))->middleware('auth:sanctum');
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', fn(Request $request) => $request->user());
    Route::post('user/profile', [ProfileController::class, 'update']);
    Route::apiResource('accounts', AccountController::class);
    Route::apiResource('categories', CategoryController::class);
    Route::apiResource('transactions', TransactionController::class)->only(['index', 'store', 'show', 'destroy']);
    Route::apiResource('budgets', BudgetController::class)->only(['index', 'store', 'destroy']);
    Route::apiResource('goals', GoalController::class);
    Route::post('goals/{goal}/contributions', [GoalController::class, 'addContribution']);
});
