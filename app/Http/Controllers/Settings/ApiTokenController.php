<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ApiTokenStoreRequest;
use App\Mcp\Servers\PixelforgeStudioServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    /** Tokens spend credits, so they do not live forever. */
    private const int LIFETIME_DAYS = 90;

    public function index(Request $request): Response
    {
        return Inertia::render('settings/ApiTokens', [
            'tokens' => $this->authenticatedUser($request)->tokens()->latest('id')->get()
                ->map(fn (PersonalAccessToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'created_at' => $token->created_at?->toIso8601String(),
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                ])->all(),
            'apiUrl' => url('api/v1'),
            'mcpUrl' => url(PixelforgeStudioServer::PATH),
        ]);
    }

    /**
     * The plain token exists only in this response: it is flashed once and
     * only its hash is stored.
     */
    public function store(ApiTokenStoreRequest $request): RedirectResponse
    {
        $token = $this->authenticatedUser($request)->createToken(
            $request->string('name')->toString(),
            expiresAt: now()->addDays(self::LIFETIME_DAYS),
        );

        Inertia::flash('apiToken', $token->plainTextToken);

        return to_route('api-tokens.index');
    }

    public function destroy(Request $request, int $token): RedirectResponse
    {
        $this->authenticatedUser($request)->tokens()->whereKey($token)->firstOrFail()->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Token revoked.')]);

        return to_route('api-tokens.index');
    }
}
