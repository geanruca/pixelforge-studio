# Pixelforge Studio

Take-home for the Senior Product Engineer role at PromptEdit: an app where signed-in users generate
images through the [Pixelforge API](https://pixelforge.promptedit.dev/docs) and every generation is
recorded at exactly what it cost.

Laravel 13 · Vue 3 · Inertia 3 · PostgreSQL · database queue.

- **Live:** https://pixelforge.24.199.64.10.sslip.io (sign up, press "Buy credits", generate)
- **Requirements and edge cases, each mapped to its test:** [`docs/REQUIREMENTS.md`](docs/REQUIREMENTS.md)
- **Required:** generate page with a live price estimate, pricing with a 25% markup, live history,
  signed webhooks that survive duplicates and reordering.
- **Stretch, both done:** credit balances with a fake "Buy credits", and an operator report checked
  against `GET /v1/usage`.

## How a generation flows

```
Browser ──POST /generations──▶ GenerationCreator        one DB transaction:
                                 ├─ generation row (queued, idempotency key)
                                 └─ ledger HOLD for the worst-case price
                                          │ after commit
                                          ▼
                               SubmitGeneration (queue) ──POST /v1/generations──▶ Pixelforge
                                 429 / 503 / timeout → wait Retry-After, try again (same idempotency key)
                                 400 / 422 / …       → failed, hold released

Pixelforge ──webhook──▶ PixelforgeWebhookController ─┐
Create response ─────────────────────────────────────┼─▶ GenerationSynchronizer::apply(snapshot)
generations:reconcile (every minute, polling) ───────┘     ├─ locks the generation row
                                                           ├─ only moves the status FORWARD
                                                           └─ succeeded → release hold + CHARGE actual price
                                                              failed    → release hold (free)
```

The three sources of truth about a generation (the create response, webhooks, polling) all carry the
same Pixelforge generation object, so they are parsed into one `ProviderGeneration` snapshot and applied
by one class. That is what makes ordering irrelevant: there is no code path where "the webhook handler"
and "the poller" can disagree.

## Decisions I would defend

**Money is integer nanodollars.** Pixelforge bills with nine decimals, so 1 USD = 1,000,000,000 units
represents every cost exactly. Rates become whole nanodollars per token (`$12.50 / 1M` = 12,500), a cost
is an integer sum, and no float ever touches an amount. A rate or amount that would need rounding is
refused loudly instead of rounded (`App\Support\Money`).

**Sub-cent amounts are charged exactly, not rounded to cents.** A typical generation costs under a
cent to seven cents; rounding each to $0.01 would either give work away or multiply its price. Balances
and charges keep full precision, the UI shows four decimals (exact value on hover), and the only rounding
is _up_ to a whole nanodollar when the 25% does not divide evenly, so the margin is never below 25%.

**The estimate is a range, and the worst case is put on hold.** Text tokens vary ±15% and reasoning
tokens anywhere in the quality's range, so the price is only known at completion. The page shows the
expected price and the low–high range; submitting holds the _maximum_ on the balance. On completion the
hold is released and the actual price charged in the same transaction. A user can therefore never spend
credits they do not have, even with several generations in flight.

**Double charging is prevented three times over.** (1) Webhook event ids are claimed in a unique column
in the same transaction as their effect. (2) The synchronizer refuses to apply a state that does not move
the generation forward. (3) The ledger has a unique index on `(generation_id, type)`, so even buggy code
cannot write a second charge. Each layer is tested separately.

**Submitting happens in a queue.** Pixelforge allows 3 concurrent generations per account and answers
429/503 routinely. Waiting for one of the three slots is normal, so the job just waits its turn (up to
15 minutes) and does not spend a request while it knows all slots are taken. Overloads, rate limits and
timeouts are different: the job backs off exponentially, never sooner than `Retry-After`, and stops
after six attempts with the hold refunded. The user sees the generation as queued the whole time.

**"We gave up" is not the same as "it failed".** A timed-out create may have been accepted. Rejections
Pixelforge states explicitly (content policy, validation, daily limit) are final; giving up after
timeouts is recorded as _unconfirmed_, the hold is refunded, and if Pixelforge later delivers the image
its webhook is still applied and billed. A final state that Pixelforge itself confirmed never changes.

**Webhooks are a fast path, not the only path.** Delivery is at-least-once but all six attempts can
fail while we are down, so `generations:reconcile` polls any in-flight generation that has gone quiet for
45 seconds.

**Images are copied to our own storage.** Pixelforge's URL expires 24 hours after completion, so a job
stores the PNG and the history page serves it through an authorised route. If the copy never lands and
the URL expires, the card says so instead of showing a broken image.

**Days in the admin report are UTC days of completion,** because that is the day Pixelforge bills on.
The report compares each day with `GET /v1/usage` to the nanodollar and flags any difference.

## What I left out, on purpose

- **Websockets.** History polls every 2.5 s only while something is in flight. Reverb would be the next
  step; polling is simpler to run and to reason about.
- **Real payments.** "Buy credits" credits a fixed pack, idempotently.
- **A public API.** The only machine-facing endpoint is the inbound webhook.
- **Concurrency tests against a real database with parallel connections.** Row locks and unique indexes
  are in place and the suite also passes on PostgreSQL, but races are tested sequentially.

## Checked against the live API

Besides the automated suite, the deployed app was exercised against Pixelforge itself:

| Scenario                                                                         | Result                                                                                |
| -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------- |
| `[succeed]`, plain prompts, Unicode prompt, reference image, all three qualities | charged the exact `usage × rates × 1.25`; image copied to our storage                 |
| `[fail]`                                                                         | failed, hold released, "not charged"                                                  |
| `[blocked]`                                                                      | rejected at submit, failed immediately with the content-policy message, hold released |
| `[overloaded]`                                                                   | backs off, gives up after six attempts, hold released                                 |
| `[slow]`                                                                         | stays "processing" for over a minute, then settles                                    |
| `[duplicate]`                                                                    | every event delivered twice; second delivery answered `200 duplicate`, charged once   |
| Eight submitted at once                                                          | three run, the rest wait for a slot and follow as slots free up                       |
| Provider cost vs `GET /v1/usage`                                                 | identical to the nanodollar; ledger sum equals the balance                            |

## Running it locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
# set DB_*, PIXELFORGE_API_KEY, PIXELFORGE_WEBHOOK_SECRET and ADMIN_EMAILS in .env
php artisan migrate
composer run dev            # web server, queue worker, logs and Vite
php artisan schedule:work   # runs generations:reconcile every minute
```

Webhooks need a public URL: expose the app with a tunnel (Expose, ngrok, Cloudflare Tunnel) and set
`https://<tunnel>/api/webhooks/pixelforge` as the webhook URL of your **local** API key. Use a different
key per environment — each key has its own webhook URL, signing secret and usage report.

`ADMIN_EMAILS` is a comma-separated list of accounts allowed to open `/admin/report`.

## Quality

```bash
php artisan test       # PHPUnit: 191 tests
composer phpstan       # Larastan, level 9, no baseline
composer pfix          # Laravel Pint
npm run check          # lint + format
npm run types:check    # vue-tsc
```

## Map of the code

| Concern                                     | Where                                                  |
| ------------------------------------------- | ------------------------------------------------------ |
| Exact money                                 | `app/Support/Money.php`                                |
| Rates, token rules, estimates, markup       | `app/Services/Pricing/`                                |
| Pixelforge HTTP client, snapshot, signature | `app/Services/Pixelforge/`                             |
| State machine and billing on completion     | `app/Services/Generations/GenerationSynchronizer.php`  |
| Balance, holds, charges                     | `app/Services/Credits/CreditLedger.php`                |
| Submission with retries                     | `app/Jobs/SubmitGeneration.php`                        |
| Webhook endpoint                            | `app/Http/Controllers/PixelforgeWebhookController.php` |
| Polling safety net                          | `app/Console/Commands/ReconcileGenerations.php`        |
| Operator report                             | `app/Services/Reports/SpendReport.php`                 |
