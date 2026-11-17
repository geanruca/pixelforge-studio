<?php

use App\Mcp\Servers\PixelforgeStudioServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Middleware\AddWwwAuthenticateHeader;

/*
| The MCP server, loaded by laravel/mcp outside the `web` group: stateless, no
| session or CSRF. It is authenticated and rate limited exactly like the API
| v1. AddWwwAuthenticateHeader goes first so a 401 carries the
| `WWW-Authenticate: Bearer` challenge MCP clients expect.
*/
Route::middleware([AddWwwAuthenticateHeader::class, 'auth:sanctum', 'throttle:api'])->group(function (): void {
    Mcp::web(PixelforgeStudioServer::PATH, PixelforgeStudioServer::class)->name('mcp');
});
