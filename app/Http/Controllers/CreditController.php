<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseCreditsRequest;
use App\Models\LedgerEntry;
use App\Services\Credits\CreditLedger;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CreditController extends Controller
{
    private const int RECENT_ENTRIES = 50;

    public function index(Request $request): Response
    {
        $entries = $this->authenticatedUser($request)->ledgerEntries()->latest('id')->limit(self::RECENT_ENTRIES)->get();

        return Inertia::render('credits/Index', [
            'packs' => config()->array('pixelforge.credit_packs_usd'),
            'entries' => $entries->map(fn (LedgerEntry $entry): array => [
                'id' => $entry->id,
                'type' => $entry->type->value,
                'label' => $entry->type->label(),
                'generation_id' => $entry->generation_id,
                'amount_nanos' => $entry->amount_nanos,
                'balance_after_nanos' => $entry->balance_after_nanos,
                'created_at' => $entry->created_at->toIso8601String(),
            ])->all(),
        ]);
    }

    /**
     * The fake checkout: no payment happens, the pack is simply credited.
     */
    public function store(PurchaseCreditsRequest $request, CreditLedger $ledger): RedirectResponse
    {
        $ledger->purchase(
            $this->authenticatedUser($request),
            Money::fromDollars($request->integer('pack')),
            $request->string('reference')->toString(),
        );

        return back();
    }
}
