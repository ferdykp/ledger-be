# WhatsApp transaction processing

## Deployment

1. Deploy the backend and run `php artisan migrate` before accepting new webhooks. The migration adds `reply_text` and `replied_at` to the existing message inbox, without dropping financial data.
2. Set `EVOLUTION_WEBHOOK_SECRET` and configure the provider to send the identical value in `X-Ledger-Webhook-Secret`. An unset secret returns HTTP 503; a missing/wrong header returns HTTP 401.
3. Configure provider retry/backoff for non-2xx responses. Delivery failures return 502. Processing failures leave the inbox row unprocessed and retryable.
4. Keep provider message IDs stable across retries. Missing IDs are rejected. Outbound echoes, groups, non-message events, and opaque `@lid` identities are ignored; phone-based messages use `@s.whatsapp.net` or a numeric sender.
5. Pair through the existing OTP page. Legacy `/integrations/whatsapp/pairing` now returns 410, and `LINK` chat commands direct the user to OTP. Disconnect clears pending conversation and verification state.

## Consistency

- A unique inbox ID prevents the same webhook from becoming a second financial operation.
- The user and conversation rows are locked while processing a draft/confirmation. Saving the transaction, clearing the draft and storing the reply commit together.
- Replies are sent after financial processing commits. A delivery retry uses the stored reply instead of processing the transaction again.
- Replies are at-least-once: a process crash after the provider accepts a message but before `replied_at` commits can repeat the reply, but not the financial transaction.
- Two distinct confirmation messages are serialized by user/session locking; the second sees an idle session.
- Provider calls remain synchronous with bounded timeouts. Large deployments should dispatch delivery through a queue; they currently depend on provider retries, not an installed scheduler.

## Parsing and validation

Examples: `gaji 1.000.000 bca`, `gaji 1,5jt bca`, `makan 35k gopay`, `transfer 50rb bca ke gopay`. Transfer accounts follow text order with `ke`/`to` between them. The confirmation includes both source and destination. Ambiguous values require clarification. AI fallback cannot override transfer direction or an already classified type, and invalid/timeout responses are ignored.

Web forms and WhatsApp share `TransactionRules`. Validate again at confirmation to catch changed/deleted categories, unavailable accounts, invalid amounts and dates. No external WhatsApp messages are sent by the automated tests.

## Verification

`php artisan test` includes parser, webhook authentication, echo/group filtering, processing retry, reply retry, duplicate confirmations, expired/cancelled drafts, disconnect, and AI failure cases. Tests use SQLite and mocked providers; production database concurrency and real Evolution delivery still need deployment smoke tests.
