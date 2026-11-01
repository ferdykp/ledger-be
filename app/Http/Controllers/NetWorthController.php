<?php

namespace App\Http\Controllers;

use App\Services\NetWorthService;
use Illuminate\Http\Request;

class NetWorthController extends Controller
{
    public function __invoke(Request $request, NetWorthService $service)
    {
        return response()->json(['data' => $service->summary($request->user())]);
    }
}
