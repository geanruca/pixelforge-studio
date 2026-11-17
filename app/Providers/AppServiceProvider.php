<?php

namespace App\Providers;

use App\Contracts\ImageProvider;
use App\Models\User;
use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Resilience\CircuitBreaker;
use App\Services\Resilience\ResilientImageProvider;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Everything that needs an image provider asks for the contract and
        // gets Pixelforge behind a circuit breaker. A second provider would be
        // bound here as the `fallback` argument.
        $this->app->bind(ImageProvider::class, fn (Application $app): ImageProvider => new ResilientImageProvider(
            primary: $app->make(PixelforgeClient::class),
            breaker: $app->make(CircuitBreaker::class),
            fallback: null,
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('view-admin-report', fn (User $user): bool => $user->isAdmin());

        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user();

            return Limit::perMinute(60)->by($user instanceof User ? "user:{$user->id}" : "ip:{$request->ip()}");
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
