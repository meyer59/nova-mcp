<?php

namespace NovaMcp;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use NovaMcp\Auth\ProviderResolver;
use NovaMcp\Models\Token;
use NovaMcp\Support\Audit;

class NovaMcp
{
    public static function token(?Request $request = null): ?Token
    {
        $token = ($request ?? request())->attributes->get('nova-mcp.token');

        return $token instanceof Token ? $token : null;
    }

    public static function isMcpRequest(?Request $request = null): bool
    {
        return self::token($request) !== null;
    }

    public static function manageTokensUsing(Closure $callback): void
    {
        Gate::define('manageNovaMcpTokens', $callback);
    }

    /**
     * Trusted application code may revoke an owner's tokens after a lifecycle event.
     * This is not an HTTP endpoint and does not infer authorization from a session.
     */
    public static function revokeTokensFor(Authenticatable $user, ?string $reason = null): int
    {
        if ($reason !== null && ! preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $reason)) {
            throw new \InvalidArgumentException('Use a short event code for the revocation reason.');
        }
        $providers = app(ProviderResolver::class);

        return DB::transaction(function () use ($providers, $user, $reason) {
            $count = Token::query()
                ->where('provider', $providers->name())
                ->where('tokenable_type', $providers->type($user))
                ->where('tokenable_id', (string) $user->getAuthIdentifier())
                ->whereNull('revoked_at')->update(['revoked_at' => now()]);
            app(Audit::class)->record('token.revoked_bulk', [
                'provider' => $providers->name(), 'owner_type' => $providers->type($user),
                'owner_id' => (string) $user->getAuthIdentifier(), 'count' => $count, 'reason' => $reason,
            ]);

            return $count;
        });
    }

    public static function canManage(Authenticatable $actor, Authenticatable $target): bool
    {
        return ($actor::class === $target::class && (string) $actor->getAuthIdentifier() === (string) $target->getAuthIdentifier())
            || (Gate::has('manageNovaMcpTokens') && Gate::forUser($actor)->allows('manageNovaMcpTokens', [$target]));
    }
}
