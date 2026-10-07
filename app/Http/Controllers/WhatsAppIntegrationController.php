<?php

namespace App\Http\Controllers;

use App\Models\{WhatsAppConnection, WhatsAppPairingCode};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class WhatsAppIntegrationController extends Controller
{
    public function status(Request $r): JsonResponse
    {
        $c = WhatsAppConnection::where('user_id', $r->user()->id)->first();
        return response()->json(['data' => $c ? ['connected' => $c->status === 'connected', 'phone_number' => $c->phone_number, 'verified_at' => $c->verified_at] : ['connected' => false]]);
    }
    public function pairing(Request $r): JsonResponse
    {
        WhatsAppPairingCode::where('user_id', $r->user()->id)->whereNull('used_at')->delete();
        $code = (string)random_int(100000, 999999);
        WhatsAppPairingCode::create(['user_id' => $r->user()->id, 'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10)]);
        WhatsAppConnection::updateOrCreate(['user_id' => $r->user()->id], ['status' => 'pending', 'provider' => 'evolution']);
        return response()->json(['data' => ['code' => $code, 'command' => 'LINK ' . $code, 'expires_in' => 600]]);
    }
    public function disconnect(Request $r): JsonResponse
    {
        WhatsAppConnection::where('user_id', $r->user()->id)->delete();
        return response()->json(['message' => 'WhatsApp berhasil diputuskan.']);
    }
}
