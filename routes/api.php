<?php

use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Pixelforge calls this from its servers: no session, no CSRF token. It lives
| in the stateless `api` group and is authenticated by its HMAC signature.
*/
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');
