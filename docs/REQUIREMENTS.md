# Requirements checklist

Every requirement of the [assessment brief](https://pixelforge.promptedit.dev/assessment) and of the
[API reference](https://pixelforge.promptedit.dev/docs), with the edge cases considered and the test that
proves each one. Test paths are relative to `tests/`.

Legend: ✅ implemented and covered by an automated test · 🔎 verified by hand against the live API (see README).

## 1. Generating

| # | Requirement / edge case | Where | Test |
|---|---|---|---|
| 1.1 | ✅ Page with prompt, size, quality, optional reference image URLs | `pages/generations/Create.vue` | `Feature/Generations/GenerationPagesTest::test_the_generate_page_offers_the_sizes_and_qualities_pixelforge_accepts` |
| 1.2 | ✅ Cost estimate shown **before** submitting, updated as the user types | `GenerationController::estimate` | `…::test_the_estimate_shows_the_price_range_with_the_markup_included` |
| 1.3 | ✅ Only signed-in users can generate | `routes/web.php` (`auth`) | `…::test_guests_cannot_reach_any_generation_page` |
| 1.4 | ✅ Empty / whitespace-only prompt rejected | `StoreGenerationRequest` | `…::test_invalid_requests_are_rejected_before_anything_is_held` (missing, whitespace-only) |
| 1.5 | ✅ Prompt limit 4,000: 4,000 accepted, 4,001 rejected | same | same (one character too long) + `…::test_the_longest_prompt_and_four_references_are_accepted` |
| 1.6 | ✅ Unknown size / quality rejected (Pixelforge would 422) | same | same (unknown size, unknown quality) |
| 1.7 | ✅ Max 4 reference images; 5 rejected | same | same (five reference images) |
| 1.8 | ✅ Reference must be an http(s) URL (`ftp:`, `javascript:`, free text, empty rejected) | same | same (four cases) |
| 1.9 | ✅ Prompt counted in Unicode characters, not bytes (emoji) | `GenerationCreator` (`mb_strlen`) | `…::test_the_prompt_is_counted_in_unicode_characters_not_bytes` |
| 1.10 | ✅ Typing does not burn Pixelforge's 60 req/min: pricing is cached | `PricingRepository` | `…::test_estimating_does_not_spend_a_pixelforge_request_each_time` |
| 1.11 | ✅ Pixelforge down: estimate keeps working from the last known pricing | same | `…::test_the_estimate_survives_a_pixelforge_outage_using_the_last_known_pricing` |
| 1.12 | ✅ Pricing never available: clear message, nothing submitted or held | `GenerationController` | `…::test_the_estimate_says_so_when_pricing_was_never_available`, `…::test_nothing_is_submitted_or_held_when_pricing_is_unavailable` |
| 1.13 | ✅ A slow, older estimate response cannot overwrite a newer one | `Create.vue` (`latestRequest`) | by inspection |
| 1.14 | ✅ Submission is rate limited per user (30/min) | `routes/web.php` | by inspection |

## 2. Pricing

| # | Requirement / edge case | Where | Test |
|---|---|---|---|
| 2.1 | ✅ User pays provider cost **+ 25%** | `Markup` | `Unit/Pricing/PricingTableTest::test_the_markup_adds_a_quarter_on_top_of_the_cost` |
| 2.2 | ✅ Up-front price is an estimate: low / expected / high | `PricingTable::estimate` | `…::test_an_estimate_is_a_low_expected_high_triple` |
| 2.3 | ✅ Worst case equals the docs' worked example (0.373875000 USD) | same | `…::test_the_worst_case_matches_the_worked_example_in_the_pixelforge_docs` |
| 2.4 | ✅ Text tokens = ceil(chars / 4), ±15% | same | `…::test_text_tokens_are_characters_divided_by_four_rounded_up` (0, 1, 4, 5, 4000 chars) |
| 2.5 | ✅ 765 image tokens per reference image, exact | same | `…::test_each_reference_image_adds_exactly_765_image_tokens` |
| 2.6 | ✅ Any real usage inside the published ranges falls inside the estimate (all 9 size × quality combos) | same | `…::test_any_actual_usage_inside_the_published_ranges_falls_within_the_estimate` |
| 2.7 | ✅ Actual cost recorded after completion, from `usage` × the generation's own `rates` | `GenerationSynchronizer::succeed` | `Feature/Generations/GenerationSynchronizerTest::test_it_walks_the_whole_lifecycle_and_charges_the_actual_price` |
| 2.8 | ✅ Rates fixed at creation: a later price change does not reprice a generation | same | `…::test_the_cost_is_computed_from_the_generations_own_rates_not_todays` |
| 2.9 | ✅ Failed generations cost nothing | `CreditLedger::release` | `…::test_a_failed_generation_costs_nothing` |
| 2.10 | ✅ No floats anywhere: integer nanodollars | `Support\Money` | `Unit/MoneyTest` (exact parse, 0.1 + 0.2 = 0.3) |
| 2.11 | ✅ Sub-cent policy: charge the exact price, never round to cents; only round **up** to a whole nanodollar | `Markup` | `Unit/Pricing/PricingTableTest::test_a_markup_that_does_not_divide_evenly_rounds_up_to_the_next_nanodollar` |
| 2.12 | ✅ A rate or amount that cannot be represented exactly is refused, not rounded | `Money::toScaledInteger` | `…::test_a_rate_that_would_need_rounding_is_refused_instead_of_silently_rounded`, `Unit/MoneyTest::test_it_refuses_precision_it_cannot_represent` |
| 2.13 | ✅ Malformed pricing / usage payloads refused | `PricingTable`, `TokenUsage` | `…::test_a_pricing_table_missing_a_size_is_refused`, `…::test_usage_without_a_token_count_is_refused` |
| 2.14 | ✅ A generation is never charged twice (code **and** database unique index) | `CreditLedger`, `ledger_entries` unique `(generation_id, type)` | `Feature/Credits/CreditLedgerTest::test_settling_twice_charges_once`, `…::test_the_database_itself_refuses_a_second_charge_for_a_generation` |
| 2.15 | ✅ Ledger always adds up to the balance | `CreditLedger` | `assertLedgerMatchesBalance` in every money test |

## 3. History

| # | Requirement / edge case | Where | Test |
|---|---|---|---|
| 3.1 | ✅ Each generation with status, estimated cost, actual cost and image | `pages/generations/Index.vue` | `Feature/Generations/GenerationPagesTest::test_history_lists_only_the_users_own_generations_newest_first` |
| 3.2 | ✅ Live status (polls every 2.5 s only while something is in flight) | same (`usePoll`) | `…::test_history_stops_polling_once_nothing_is_in_flight` |
| 3.3 | ✅ Users only see their own generations | `GenerationController::index` | 3.1 |
| 3.4 | ✅ Nobody can load another user's image | `GenerationPolicy` | `…::test_nobody_can_load_another_users_image` |
| 3.5 | ✅ Expired image URLs: finished images are copied to our own storage | `StoreGenerationImage` | `Feature/Console/ReconcileGenerationsTest::test_the_image_job_copies_the_png_to_our_own_disk`, `…GenerationPagesTest::test_the_stored_copy_is_served_even_after_the_provider_url_expired` |
| 3.6 | ✅ Copy still pending: provider URL used meanwhile | `GenerationController::image` | `…::test_the_provider_url_is_used_while_the_copy_is_still_pending` |
| 3.7 | ✅ Expired and never copied: "no longer available" placeholder, not a broken image | `GenerationResource`, `Index.vue` (`@error`) | `…::test_an_expired_image_that_was_never_copied_is_reported_as_expired_not_broken` |
| 3.8 | ✅ Failed copy is retried (job backoff, then the reconcile command) | `StoreGenerationImage`, `ReconcileGenerations` | `…ReconcileGenerationsTest::test_it_retries_image_copies_that_never_landed_while_the_url_still_works`, `…::test_the_image_job_fails_loudly_so_it_is_retried_when_the_download_fails` |
| 3.9 | ✅ Failures explain themselves in plain words and say "not charged" | `Generation::failureMessage` | `…GenerationPagesTest::test_a_failed_generation_explains_itself_and_shows_no_charge` |
| 3.10 | ✅ Not yet accepted by Pixelforge is shown as "waiting for a free slot" | `GenerationResource` | `…::test_a_generation_not_yet_accepted_by_pixelforge_is_shown_as_waiting` |
| 3.11 | ✅ "Try again" prefills the form, only from the user's own generation | `GenerationController::create` | `…::test_try_again_prefills_the_form_only_from_the_users_own_generation` |

## 4. Webhooks

| # | Requirement / edge case | Where | Test (all in `Feature/Webhooks/PixelforgeWebhookTest`) |
|---|---|---|---|
| 4.1 | ✅ Signature verified (HMAC-SHA256 of `t.rawBody`, constant time) | `WebhookSignature` | `test_a_wrong_signature_is_rejected_and_changes_nothing` |
| 4.2 | ✅ Verified against the **raw** body: a body altered after signing is rejected | same | `test_a_body_altered_after_signing_is_rejected` |
| 4.3 | ✅ Replay protection: timestamps older/newer than 300 s rejected | same | `test_a_replayed_old_delivery_is_rejected` |
| 4.4 | ✅ Missing or garbage signature header rejected | same | `test_a_request_without_a_signature_header_is_rejected` |
| 4.5 | ✅ No signing secret configured: everything rejected (fail closed) | same | `test_nothing_is_accepted_while_no_signing_secret_is_configured` |
| 4.6 | ✅ Duplicate event (same `evt_` id) acknowledged with 200 but applied once | `webhook_events.event_id` unique | `test_a_duplicate_event_is_acknowledged_but_recorded_once` |
| 4.7 | ✅ Same outcome under a different event id still charges once | `GenerationSynchronizer` + ledger | `test_the_same_outcome_under_two_different_event_ids_still_charges_once` |
| 4.8 | ✅ Out of order (`processing` after `succeeded`) ends in the right state | `GenerationStatus::rank` | `test_events_arriving_out_of_order_end_in_the_right_state` |
| 4.9 | ✅ Final states never move back; succeeded never becomes failed or vice versa | `GenerationSynchronizer::movesForward` | `GenerationSynchronizerTest::test_a_final_state_confirmed_by_the_provider_never_changes` |
| 4.10 | ✅ Uses the generation status inside the event, not the event type or order | `PixelforgeWebhookController` | `test_the_status_inside_the_event_wins_over_the_event_type` |
| 4.11 | ✅ Webhook arrives **before** the create request returns | `metadata.generation_id` lookup | `test_an_event_that_beats_the_create_response_finds_the_generation_by_its_metadata`, `SubmitGenerationTest::test_a_webhook_that_already_finished_the_generation_is_not_undone_by_the_create_response` |
| 4.12 | ✅ `succeeded` without ever receiving `processing` | `GenerationSynchronizer` | `GenerationSynchronizerTest::test_succeeded_can_arrive_without_processing_ever_arriving` |
| 4.13 | ✅ `ping` acknowledged | controller | `test_a_ping_is_acknowledged` |
| 4.14 | ✅ Event for an unknown generation: 200, logged, no effect | controller | `test_an_event_for_a_generation_we_do_not_know_is_acknowledged_without_effect` |
| 4.15 | ✅ Signed but malformed body: 400 | controller | `test_a_signed_body_that_is_not_an_event_is_a_bad_request` |
| 4.16 | ✅ Unusable event (succeeded without usage): refused, and **not claimed**, so the retry can apply | claim + effect in one transaction | `test_an_unusable_succeeded_event_is_refused_and_its_retry_can_still_apply` |
| 4.17 | ✅ No CSRF / session needed (route in the `api` group) | `routes/api.php` | `test_the_webhook_needs_no_session_or_csrf_token` |
| 4.18 | ✅ Answers well inside 10 s: a few indexed queries, no outbound call | controller | by design |
| 4.19 | ✅ All 6 delivery attempts lost: polling `GET /generations/{id}` takes over | `generations:reconcile` (every minute) | `ReconcileGenerationsTest::test_a_generation_whose_webhooks_never_arrived_is_completed_by_polling` |
| 4.20 | ✅ Polling and a late webhook together still charge once | synchronizer | `ReconcileGenerationsTest::test_polling_after_the_webhook_already_applied_does_not_charge_twice` |

## 5. Robustness against Pixelforge (limits, overloads, failures)

| # | Edge case | Where | Test (all in `Feature/Generations/SubmitGenerationTest` unless noted) |
|---|---|---|---|
| 5.1 | ✅ `429 concurrency_limit_exceeded`: wait `Retry-After`, retry, user sees "queued" | `SubmitGeneration` | `test_temporary_rejections_wait_and_retry_without_failing_or_charging` |
| 5.2 | ✅ `429 rate_limit_exceeded`: wait `Retry-After` | same | same |
| 5.3 | ✅ `503 overloaded`: wait `Retry-After`; default wait when the header is missing | same | same |
| 5.4 | ✅ Absurd `Retry-After` capped (60 s) | same | same |
| 5.5 | ✅ Unreadable 5xx retried, never treated as final | `PixelforgeException` | same |
| 5.6 | ✅ Timeout / connection reset: retried with the **same idempotency key**, so no second generation | `PixelforgeClient` | `test_a_connection_failure_is_retried_because_the_request_may_have_been_accepted`, `test_it_submits_the_generation_and_records_the_provider_id` |
| 5.7 | ✅ Idempotent replay returns an already-finished generation: settled from that response | synchronizer | `test_an_idempotent_replay_that_already_succeeded_is_settled_from_the_response` |
| 5.8 | ✅ 2xx with an unreadable body: retried, not failed | client | `test_a_success_response_we_cannot_read_is_retried_not_failed` |
| 5.9 | ✅ `400 content_policy_violation`, `422 validation_failed`, `429 daily_limit_exceeded`, `401 invalid_api_key`, `409 idempotency_key_reused`: fail at once, hold refunded, clear message | `SubmitGeneration` | `test_definitive_rejections_fail_the_generation_and_refund_the_hold` |
| 5.10 | ✅ We do not spend requests we know will be refused (3 slots taken) | `Generation::scopeInFlight` | `test_it_does_not_call_pixelforge_while_all_concurrency_slots_are_taken`, `test_finished_generations_do_not_occupy_a_slot` |
| 5.11 | ✅ Retries stop: after the submission window the generation fails and the hold is refunded | `retryUntil` + `failed()` | `test_giving_up_after_the_submission_window_refunds_the_hold`, `test_the_submission_window_bounds_how_long_it_keeps_trying` |
| 5.12 | ✅ We gave up, but Pixelforge had accepted and later delivers: still billed correctly | `outcome_confirmed` | `GenerationSynchronizerTest::test_a_submission_we_gave_up_on_is_still_billed_if_the_provider_delivered_it` |
| 5.13 | ✅ A rejection confirmed by Pixelforge can never be reopened | same | `GenerationSynchronizerTest::test_a_rejection_confirmed_by_the_provider_cannot_be_reopened` |
| 5.14 | ✅ Failure after acceptance (`generation_failed`, `internal_error`): free, hold released | synchronizer | `PixelforgeWebhookTest::test_a_failed_event_releases_the_hold_and_charges_nothing` |
| 5.15 | ✅ Job re-run after success does not submit twice | `SubmitGeneration` | `test_a_job_that_runs_again_after_success_does_not_submit_twice` |
| 5.16 | ✅ Reconcile stops at the rate limit; one unreachable generation does not block others; unknown generation (404) is failed and refunded | `ReconcileGenerations` | `ReconcileGenerationsTest` (three tests) |
| 5.17 | ✅ Snapshot for a different provider generation refused | synchronizer | `GenerationSynchronizerTest::test_a_snapshot_for_a_different_provider_generation_is_refused` |

## 6. Stretch: balances

| # | Requirement / edge case | Where | Test |
|---|---|---|---|
| 6.1 | ✅ Per-user credit balance, visible on every page | `users.balance_nanos`, header pill | `Feature/Credits/CreditPagesTest::test_a_new_user_starts_with_no_credits` |
| 6.2 | ✅ Fake "Buy credits" button | `CreditController::store` | `…::test_buying_a_pack_adds_it_to_the_balance` |
| 6.3 | ✅ Double click / retried request credits once | purchase `reference` (unique per user) | `…::test_a_double_submitted_purchase_is_credited_once` |
| 6.4 | ✅ The user cannot pick an arbitrary amount | `PurchaseCreditsRequest` | `…::test_a_user_cannot_choose_their_own_amount` |
| 6.5 | ✅ One user's reference cannot block another's | unique `(user_id, type, reference)` | `…::test_one_users_purchase_reference_cannot_block_another_users` |
| 6.6 | ✅ Generation blocked when the user cannot afford the **worst case** | `CreditLedger::hold` | `Feature/Generations/GenerationPagesTest::test_a_user_who_cannot_afford_the_worst_case_is_blocked_and_nothing_is_created` |
| 6.7 | ✅ Credits held by in-flight generations cannot be spent twice (balance never goes negative) | hold on submit, row lock | `…::test_credits_held_by_pending_generations_cannot_be_spent_twice`, `Feature/Credits/CreditLedgerTest::test_a_hold_is_refused_when_the_balance_does_not_cover_it` |
| 6.8 | ✅ Exactly enough credits is enough | same | `…CreditLedgerTest::test_a_hold_for_exactly_the_whole_balance_is_allowed` |
| 6.9 | ✅ The unused part of the hold returns on completion; all of it on failure | `CreditLedger::settle` / `release` | `…::test_settling_releases_the_hold_and_charges_only_the_actual_price`, `…::test_releasing_gives_the_whole_hold_back_and_only_once` |

## 7. Stretch: admin report

| # | Requirement / edge case | Where | Test (all in `Feature/Admin/ReportTest`) |
|---|---|---|---|
| 7.1 | ✅ Per-day and per-user credit spend, provider cost and profit | `SpendReport` | `test_it_reports_spend_cost_and_profit_per_day_and_user_and_matches_pixelforge` |
| 7.2 | ✅ Provider costs checked against `GET /v1/usage`, to the nanodollar | same | same + `test_a_day_that_differs_from_pixelforge_is_flagged` (off by 1 nanodollar) |
| 7.3 | ✅ Billed on the UTC day of **completion**, not creation (midnight case) | same | `test_a_generation_is_reported_on_the_utc_day_it_completed_not_the_day_it_was_created` |
| 7.4 | ✅ A day Pixelforge billed that we know nothing about is shown and flagged | same | `test_a_day_that_differs_from_pixelforge_is_flagged` |
| 7.5 | ✅ Usage endpoint down: our numbers still shown, marked "not checked" | same | `test_the_report_still_shows_our_numbers_when_pixelforge_usage_is_down` |
| 7.6 | ✅ Range capped at Pixelforge's 92 days; inverted / malformed ranges rejected | `AdminReportRequest` | `test_a_range_longer_than_pixelforge_allows_is_clamped`, `test_an_inverted_or_malformed_range_is_rejected` |
| 7.7 | ✅ Operators only | gate `view-admin-report` | `test_only_operators_can_open_the_report` |
| 7.8 | ✅ Failed and in-flight generations are not counted | `SpendReport` | 7.1 |

## 8. Delivery

| # | Requirement | Status |
|---|---|---|
| 8.1 | Public GitHub repository | see README |
| 8.2 | Deployed to a public host, with a few generations left on it | see README |
| 8.3 | One API key per environment (local and production keys are different) | `.env.example` |
| 8.4 | 🔎 Live checks with `[fail]`, `[slow]`, `[duplicate]`, `[blocked]`, `[overloaded]` and dashboard resends | see README |
