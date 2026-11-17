<?php

use App\Http\Controllers\Api\V1\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Pixelforge calls this from its servers: no session, no CSRF token. It lives
| in the stateless `api` group and is authenticated by its HMAC signature.
*/
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');

/*
| Public API v1: personal access tokens (Settings → API tokens), 60 requests
| per minute per user. Its contract lives in docs-site/api-reference/openapi.yaml
| and Tests\Feature\Api\OpenApiContractTest keeps the two in step.
*/
Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('balance', [GenerationController::class, 'balance'])->name('balance');
    Route::post('estimates', [GenerationController::class, 'estimate'])->name('estimates.store');
    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('generations', [GenerationController::class, 'store'])->name('generations.store');
    Route::get('generations/{generation}', [GenerationController::class, 'show'])
        ->whereNumber('generation')
        ->name('generations.show');
});
