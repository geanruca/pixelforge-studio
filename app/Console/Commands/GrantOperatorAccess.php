<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Operator access is a flag on an existing account, granted from the server.
 * It is deliberately not derived from an email list: sign-up is open and
 * emails are not verified, so "whoever registers ops@…" must not become an
 * operator.
 */
#[Signature('operator:grant {email : Email of an existing account} {--revoke : Take the access away instead}')]
#[Description('Allow an existing account to open the operator report')]
class GrantOperatorAccess extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No account has that email. The person must sign up first.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();

        $this->info($this->option('revoke') ? "{$user->email} is no longer an operator." : "{$user->email} is now an operator.");

        return self::SUCCESS;
    }
}
