<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Asset;
use App\Models\AssetTransaction;
use App\Models\User;
use App\Support\DecimalMath;

/** A point-in-time view of tracked cash and investment assets, excluding liabilities. */
class NetWorthService
{
    public function __construct(private AssetService $assets) {}

    public function summary(User $user): array
    {
        $cash = '0.00';
        foreach (Account::where('user_id', $user->id)->whereIn('type', ['cash', 'bank', 'ewallet'])->get(['balance']) as $account) {
            $cash = DecimalMath::add($cash, (string) $account->balance);
        }

        $marketValue = '0.00';
        $bookValueFallback = '0.00';
        $unpricedAssets = 0;
        foreach (Asset::where('user_id', $user->id)->with('prices', 'transactions', 'allocations')->get() as $asset) {
            $snapshot = $this->assets->snapshot($asset);
            if ($snapshot['estimated_value'] !== null) {
                $marketValue = DecimalMath::add($marketValue, (string) $snapshot['estimated_value']);
            } else {
                // Before the first manual NAV/buyback price, retain invested principal net of disposals.
                $bookValueFallback = DecimalMath::add($bookValueFallback, (string) $snapshot['book_value']);
                if (DecimalMath::compare($snapshot['quantity'], '0.00000000', 8) > 0) $unpricedAssets++;
            }
        }

        $inTransit = '0.00';
        foreach (AssetTransaction::where('user_id', $user->id)
            ->whereNull('superseded_at')->where('type', 'subscription')->where('status', 'pending')->where('cash_debited', true)->get(['amount']) as $pending) {
            // The account has already been debited, but RDPU units are not holdings until confirmation.
            $inTransit = DecimalMath::add($inTransit, (string) $pending->amount);
        }

        $investments = DecimalMath::add(DecimalMath::add($marketValue, $bookValueFallback), $inTransit);
        return [
            'cash_total' => $cash,
            'investment_market_value' => $marketValue,
            'investment_book_value_fallback' => $bookValueFallback,
            'investment_in_transit' => $inTransit,
            'investment_total' => $investments,
            'total_assets' => DecimalMath::add($cash, $investments),
            'unpriced_assets_count' => $unpricedAssets,
            'valuation_note' => 'Total aset terpantau tidak mengurangi kewajiban. Aset tanpa harga terbaru memakai nilai buku pembelian; setoran RDPU yang sudah dibayar tetapi belum dikonfirmasi dihitung sebagai investasi dalam proses.',
        ];
    }
}
