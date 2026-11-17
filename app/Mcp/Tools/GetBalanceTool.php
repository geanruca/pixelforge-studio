<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Models\User;
use App\Support\Money;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_balance')]
#[Title('Get the credit balance')]
#[Description('Returns the credits available to spend, in USD. Amounts reserved for generations still in progress are not included.')]
#[IsReadOnly]
#[IsIdempotent]
class GetBalanceTool extends StudioTool
{
    protected function execute(Request $request, User $user): Response|ResponseFactory
    {
        $available = Money::toDecimal($user->balance_nanos);

        return $this->respond("Available credits: \${$available}.", ['available_usd' => $available, 'currency' => 'usd']);
    }
}
