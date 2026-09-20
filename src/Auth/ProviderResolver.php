<?php

namespace NovaMcp\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Auth;
use NovaMcp\Models\Token;
use RuntimeException;

class ProviderResolver
{
    public function guard(): string
    {
        return config('nova.guard') ?: config('auth.defaults.guard');
    }

    public function name(): string
    {
        $provider = config('nova-mcp.auth.provider') ?: config('auth.guards.'.$this->guard().'.provider');

        if (! is_string($provider) || $provider === '') {
            throw new RuntimeException('Nova MCP could not resolve Nova\'s user provider. Configure nova-mcp.auth.provider.');
        }

        return $provider;
    }

    public function provider(): UserProvider
    {
        return Auth::createUserProvider($this->name()) ?? throw new RuntimeException('Nova MCP user provider does not exist.');
    }

    public function type(Authenticatable $user): string
    {
        return $user::class;
    }

    public function owns(Authenticatable $user, Token $token): bool
    {
        return $token->provider === $this->name()
            && $token->tokenable_type === $this->type($user)
            && (string) $token->tokenable_id === (string) $user->getAuthIdentifier();
    }
}
