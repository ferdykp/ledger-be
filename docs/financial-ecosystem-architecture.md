# Ledger financial ecosystem architecture

## Scope and implementation boundary

The integrated release adds goal budgets and obligations, goal cash allocations, gold and RDPU holdings, asset allocation to goals, and liquidity views. Existing accounts and transactions remain the source of truth for cash balances. Existing goal contributions remain a separate savings journal and never imply cash is currently available.

## Financial invariants

1. Account balances change only through account opening balances and cash transactions.
2. Goal contributions and cash/asset allocations do not mutate account balances.
3. Cash allocations are backed by an account and cannot exceed its current unallocated balance at allocation time. Ordinary later account spending can make an allocation underfunded; this is reported as a deficit and blocks further allocation until reconciled.
4. Asset purchases create a linked cash expense with an investment purpose. Asset sales create a linked cash income only when proceeds are received. Investment-purpose cash legs do not count as household income/expense in reports.
5. Pending RDPU subscriptions can reserve/debit cash only when the user records the actual cash movement. Pending units are not holdings. Pending redemptions reserve units but create no cash until settlement.
6. Asset allocations reserve quantities (grams or fund units), not rupiah. Revaluation changes their estimated value, never their quantities.
7. Goal budget is the sum of budget items. Paid expenses are existing expense transactions linked to obligations/items. A payment reduces unpaid obligation and is counted once.
8. Goal coverage is shown as separate amounts for paid costs, allocated cash, and estimated allocated assets. Pending/stale values are visibly identified. Liquidity readiness counts settled cash only.
9. Monetary database values use DECIMAL and backend calculations use integer minor units or decimal-safe strings. Gold weight and fund units use fixed precision strings; no new financial calculation uses binary floating point.
10. Financial mutations run in database transactions, lock affected rows in stable order, and use idempotency keys where retries can repeat a request.

## Data domains

- Goals: existing `goals`, with `opening_balance`; `goal_items`; `goal_obligations`; `goal_contributions`; `goal_cash_allocations` as signed history rows; `goal_asset_allocations` as signed quantity history rows.
- Cash: existing `accounts` and `transactions`; new nullable `financial_purpose` and internal asset link distinguish investment cash legs from household income/expense.
- Assets: `assets` identifies a gold holding or RDPU product; `asset_transactions` records immutable purchase/sale lifecycle facts and confirmed quantities; `asset_prices` stores timestamped manual valuation; asset transaction rows link to the cash transaction that actually settled.
- Payments: obligations belong to goal items; existing expense transactions link to an obligation. Partial payments use multiple transactions. Refunds are linked and reduce paid amount.

## Calculation policy

- Goal budget: sum item estimated amounts (quantity times unit price, rounded to currency minor units).
- Savings journal: opening balance plus contribution entries; it is informational and excluded from available cash/funding coverage unless separately backed by a cash allocation.
- Cash allocation available: account balance less net allocations. An account balance reduction can create an allocation deficit; do not silently rewrite history.
- Asset cost basis: weighted average cost for remaining confirmed quantity, including purchase fees. Partial disposals remove proportional average cost. Realized gain is settled proceeds less disposed cost basis and sale fees. Unrealized gain uses latest manual buyback/NAV valuation less remaining cost basis.
- RDPU valuation: confirmed units multiplied by timestamped NAV. Gold valuation: confirmed grams multiplied by timestamped manual buyback price after supported fees. Stale/missing valuations are labeled and not treated as liquid cash.
- Funding coverage: paid goal expenses + net allocated cash + estimated allocated asset value. Release/consume corresponding allocation when a payment is funded from it, so the same funds cannot remain allocated and also count as paid. Display the components separately and cap only the visual progress bar, not surplus values.
- Payment readiness: settled, unallocated-to-other-obligations cash available by due date. Pending redemption and estimated asset value are not cash.
- Tracked total assets (`GET /api/reports/net-worth`): account cash + priced investment holdings (latest recorded NAV/buyback) + purchase-value fallback for holdings with no recorded price + cash-debited pending RDPU subscriptions in transit. Purchase fees reduce total assets; investment principal is never added to household consumption expense. This is a tracked-assets view and does not subtract liabilities, which Ledger does not model.

## Migration and compatibility

Migrations are additive. Existing `current_amount` is preserved and split into `opening_balance = max(current_amount - sum(existing contributions), 0)`; no historical contribution is fabricated. New API responses retain `current_amount` as a compatibility field while adding explicit savings and funding fields. Monetary API values are serialized as decimal strings (for example, `"25000.00"`) so consumers do not lose precision by parsing them as binary floating-point numbers; consumers must not assume these fields are JSON numbers. Existing transaction types stay income/expense/transfer; an internal purpose marks asset cash legs so current reports can exclude them without reclassifying investment principal as household income/expense.

## Release sequence

Implement and verify dependency stages in order, but keep all new navigation/features behind a single ecosystem release boundary until the integrated acceptance checks pass. Never run production migrations or deploy from this workspace task.
