# Pixelforge Studio

[![CI](https://github.com/geanruca/pixelforge-studio/actions/workflows/ci.yml/badge.svg)](https://github.com/geanruca/pixelforge-studio/actions/workflows/ci.yml)

Take-home for the Senior Product Engineer role at PromptEdit: an app where signed-in users generate
images through the [Pixelforge API](https://pixelforge.promptedit.dev/docs) and every generation is
recorded at exactly what it cost.

Laravel 13 · Vue 3 · Inertia 3 · Tailwind 4 · PostgreSQL · database queue.

|                            |                                                                                                                          |
| -------------------------- | ------------------------------------------------------------------------------------------------------------------------ |
| **Live app**               | https://pixelforge.24.199.64.10.sslip.io (sign up, press "Buy credits", generate)                                        |
| **Engineering notes**      | https://pixelforge.24.199.64.10.sslip.io/engineering — every decision with its problem, code and test. Public, no login. |
| **Requirements checklist** | [`docs/REQUIREMENTS.md`](docs/REQUIREMENTS.md) — the brief and its edge cases, each mapped to a test                     |
| **Fit with the role**      | [`docs/ROLE_FIT.md`](docs/ROLE_FIT.md) — what the job post asks for and where this repository shows it                   |
| **API and MCP docs**       | [`docs-site/`](docs-site) (Mintlify)                                                                                     |

## What was asked, and what is here

**Required, all done:** a generate page with a live price estimate, pricing with a 25% markup, a live
history, signed webhooks that survive duplicates and reordering, and a public deployment.

**Stretch, both done:** credit balances with a fake "Buy credits", and an operator report checked
against `GET /v1/usage`.

## Beyond the brief

Nothing below was asked for. Each item is there because the brief, the API reference or the job post
implies a problem that a production system has to answer. The
[engineering notes](https://pixelforge.24.199.64.10.sslip.io/engineering) explain each one.

| Added                                         | Why                                                                                                                                            |
| --------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------- |
| **Provider contract + dependency injection**  | Callers depend on `ImageProvider`, never on the Pixelforge client, so protection, a second provider or a test double is one container binding. |
| **Circuit breaker with a fallback seam**      | Five consecutive timeouts or 5xx pause calls for 30 s, then probe. A fallback provider can be bound; each caller also degrades on its own.     |
| **Idempotency at every hop**                  | Our call to Pixelforge, API clients, AI agents, the credit purchase and webhooks are each idempotent, and each is tested.                      |
| **Round-robin between users**                 | Three provider slots are shared by everyone. One user with twenty requests does not make the rest wait behind all twenty.                      |
| **Workers that do not collide**               | A slot is checked and taken under one lock; submissions and image downloads run on separate queues.                                            |
| **Exponential backoff with a stop**           | Found on the live API: an overloaded request was retried every 2 s for 15 minutes. Now it backs off and stops after six attempts.              |
| **Polling and a sweeper behind the webhooks** | Lost webhooks and lost queue jobs can no longer leave credits on hold forever.                                                                 |
| **A live waiting screen**                     | Instead of a spinner: credits reserved, place in line, retries, time running, what will be charged.                                            |
| **Asking for missing data in context**        | The image's name is asked for while it generates; running out of credits is fixed inside the form without losing the prompt.                   |
| **Images copied to our storage**              | Pixelforge's links die after 24 hours.                                                                                                         |
| **REST API with OpenAPI, and an MCP server**  | Two more doors to the same services, with a contract test that fails when code and docs drift.                                                 |
| **Operations panel**                          | Provider health, slots in use, the line, stuck generations and failures by reason: where an investigation starts.                              |
| **Operator access granted from the server**   | Sign-up is open and emails are unverified, so access cannot hang on an email address.                                                          |
| **An independent review, acted on**           | It found a hold that could be stuck by a lost job and an accepted generation that could drop out of polling. Both are fixed and tested.        |
| **CI on SQLite and PostgreSQL**               | Pint, PHPStan level 9 without a baseline, frontend lint and types, and the suite on both databases.                                            |

## How a generation flows

```
Browser / API / MCP ──▶ GenerationCreator          one DB transaction:
                          ├─ generation row (queued, idempotency key)
                          └─ ledger HOLD for the worst-case price
                                   │ after commit
                                   ▼
                        SubmitGeneration (queue "submissions")
                          SlotAllocator: is it this user's turn for one of 3 slots?
                          ImageProvider ─▶ circuit breaker ─▶ Pixelforge
                            429 / 503 / timeout → back off, try again (same idempotency key)
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
is an integer sum, and no float touches an amount. A rate or amount that would need rounding is refused
loudly instead of rounded (`App\Support\Money`).

**Sub-cent amounts are charged exactly, not rounded to cents.** A typical generation costs between one
and ten cents; rounding each to $0.01 would either give work away or multiply its price. Balances and
charges keep full precision, the UI shows four decimals (exact value on hover), and the only rounding is
_up_ to a whole nanodollar when the 25% does not divide evenly, so the margin is never below 25%.

**The estimate is a range, and the worst case is put on hold.** Text tokens vary ±15% and reasoning
tokens anywhere in the quality's range, so the price is only known at completion. The page shows the
expected price and the low–high range; submitting holds the _maximum_. On completion the hold is released
and the actual price charged in the same transaction. The hold is sized from current pricing, and pricing
older than six hours is refused rather than trusted.

**Double charging is prevented three times over.** (1) Webhook event ids are claimed in a unique column
in the same transaction as their effect. (2) The synchronizer refuses to apply a state that does not move
the generation forward. (3) The ledger has a unique index on `(generation_id, type)`, so even buggy code
cannot write a second charge. Each layer is tested separately.

**"We gave up" is not the same as "it failed".** A timed-out create may have been accepted. Only
Pixelforge's own definitive error codes close a generation for good, as an allowlist; giving up after
timeouts is recorded as _unconfirmed_ and the hold refunded. Once Pixelforge has accepted a generation it
cannot be closed locally at all, and polling keeps asking until Pixelforge confirms how it ended.

**Days in the admin report are UTC days of completion,** because that is the day Pixelforge bills on.
The report compares each day with `GET /v1/usage` to the nanodollar and flags any difference.

## Known limits

- **Rates finer than a nanodollar per token** (more than three decimals of USD per 1M tokens) are refused
  rather than approximated. Today's rates have two decimals.
- **Races are tested sequentially.** Row locks and unique indexes are in place and the suite passes on
  PostgreSQL, but there is no test that drives parallel connections.
- **The second provider does not exist.** The contract, the breaker and the fallback binding do; a real
  second provider would also need each generation to remember which provider served it.
- **No websockets.** History polls every 2 s only while something is in flight. Reverb is the next step.
- **Payments are fake.** "Buy credits" credits a fixed pack, idempotently.

## What I would add next

- **A transactional outbox** for dispatching jobs, instead of relying on after-commit dispatch.
- **Per-user quotas and weighted fairness**, so a paying tier can buy a larger share of the slots.
- **A client-side token bucket** for the provider's 60 requests per minute, so we never reach its 429.
- **Bulkheads**: one worker pool per provider, so a slow provider cannot starve another.
- **Product analytics in an analytical store** (ClickHouse): the ledger and the webhook events are
  already append-only streams, which is the shape such a store wants.
- **Alerting** on the operations panel's numbers: an open circuit, a growing line, stuck generations, a
  day that does not reconcile.

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
# set DB_*, PIXELFORGE_API_KEY and PIXELFORGE_WEBHOOK_SECRET in .env
php artisan migrate
composer run dev                                     # web server, logs and Vite
php artisan queue:work --queue=submissions,images    # submissions first
php artisan schedule:work                            # generations:reconcile every minute
php artisan operator:grant you@example.com           # after signing up: open /admin/report
```

Webhooks need a public URL: expose the app with a tunnel (Expose, ngrok, Cloudflare Tunnel) and set
`https://<tunnel>/api/webhooks/pixelforge` as the webhook URL of your **local** API key. Use a different
key per environment: each key has its own webhook URL, signing secret and usage report.

## Quality

```bash
php artisan test       # PHPUnit: 276 tests
composer phpstan       # Larastan, level 9, no baseline
composer pfix          # Laravel Pint
npm run check          # lint + format
npm run types:check    # vue-tsc
```

## Deployment

Runs on a small VPS managed with Laravel Forge rather than a serverless platform: nginx and PHP-FPM, two
supervised `queue:work` processes on the `submissions` and `images` queues, a cron entry for the
scheduler, PostgreSQL, and Let's Encrypt. Pushing to `main` deploys.

## Map of the code

| Concern                                     | Where                                                                  |
| ------------------------------------------- | ---------------------------------------------------------------------- |
| Exact money                                 | `app/Support/Money.php`                                                |
| Rates, token rules, estimates, markup       | `app/Services/Pricing/`                                                |
| Provider contract                           | `app/Contracts/ImageProvider.php`                                      |
| Pixelforge HTTP client, snapshot, signature | `app/Services/Pixelforge/`                                             |
| Circuit breaker and fallback                | `app/Services/Resilience/`                                             |
| State machine and billing on completion     | `app/Services/Generations/GenerationSynchronizer.php`                  |
| Fair slot allocation                        | `app/Services/Generations/SlotAllocator.php`                           |
| Balance, holds, charges                     | `app/Services/Credits/CreditLedger.php`                                |
| Submission with retries                     | `app/Jobs/SubmitGeneration.php`                                        |
| Webhook endpoint                            | `app/Http/Controllers/PixelforgeWebhookController.php`                 |
| Polling and sweeping                        | `app/Console/Commands/ReconcileGenerations.php`                        |
| Operator report and operations panel        | `app/Services/Reports/`                                                |
| REST API v1                                 | `app/Http/Controllers/Api/V1/`, `docs-site/api-reference/openapi.yaml` |
| MCP server                                  | `app/Mcp/`                                                             |
| Live waiting screen                         | `resources/js/components/LiveGenerationProgress.vue`                   |
| Engineering notes (data)                    | `app/Support/EngineeringNotes.php`                                     |
