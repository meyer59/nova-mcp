<?php

namespace NovaMcp;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

class NovaMcp
{
    public static function manageTokensUsing(Closure $callback): void
    {
        Gate::define('manageNovaMcpTokens', $callback);
    }

    public static function canManage(Authenticatable $actor, Authenticatable $target): bool
    {
        return ($actor::class === $target::class && (string) $actor->getAuthIdentifier() === (string) $target->getAuthIdentifier())
            || (Gate::has('manageNovaMcpTokens') && Gate::forUser($actor)->allows('manageNovaMcpTokens', [$target]));
    }
}
