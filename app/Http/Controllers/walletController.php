<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Wallet;
use Illuminate\Support\Facades\Auth;

class walletController extends Controller
{
    public function getBalance()
    {
        $client = Auth::user();
        $wallet = Wallet::where('client_id', $client->id)->first();
        return response()->json([
            'balance' => $wallet->balance,
            'currency' => $wallet->currency,
            'active' => $wallet->status,
        ]);
    }
}
