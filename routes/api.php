<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Route::get('/ping', fn() => response()->json(['message' => 'pong']))->middleware('auth:sanctum');

Route::get('/ping', fn() => response()->json(['message' => 'pong']));
