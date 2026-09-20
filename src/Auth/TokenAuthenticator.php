<?php

namespace NovaMcp\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use NovaMcp\Models\Token;

class TokenAuthenticator
{
    public function __construct(private ProviderResolver $providers) {}

    public function authenticate(Request $request): ?Authenticatable
    {
        $request->attributes->remove('nova-mcp.token');
        $headers = $request->headers->all('authorization');
        // Laravel's bearerToken() accepts embedded schemes and comma-separated
        // credentials. Require one unambiguous credential at this boundary.
        if (count($headers) !== 1 || ! is_string($headers[0]) || strlen($headers[0]) > 256
            || ! preg_match('/^(?i:Bearer) +nvm_([1-9][0-9]{0,19})_([a-f0-9]{64})$/D', $headers[0], $parts)) {
            return null;
        }

        $token = Token::query()->find($parts[1]);
        if (! $token || ! hash_equals($token->token, hash('sha256', $parts[2])) || ! $token->active()
            || $token->provider !== $this->providers->name()) {
            return null;
        }

        // Resolve through the configured provider, never instantiate a class from DB input.
        $user = $this->providers->provider()->retrieveById($token->tokenable_id);
        if (! $user || ! $this->providers->owns($user, $token)) {
            return null;
        }

        $request->attributes->set('nova-mcp.token', $token);

        return $user;
    }
}
