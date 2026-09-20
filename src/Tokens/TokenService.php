<?php

namespace NovaMcp\Tokens;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use NovaMcp\Auth\ProviderResolver;
use NovaMcp\Models\Token;
use NovaMcp\NovaMcp;
use NovaMcp\Support\Audit;

class TokenService
{
    public const ABILITIES = ['read', 'create', 'update', 'delete', 'restore', 'actions', 'relationships', '*'];

    public function __construct(private ProviderResolver $providers, private Audit $audit) {}

    /** @return Builder<Token> */
    public function query(Authenticatable $actor, Authenticatable $target): Builder
    {
        abort_unless(NovaMcp::canManage($actor, $target), 403);

        return Token::query()->where('provider', $this->providers->name())
            ->where('tokenable_type', $this->providers->type($target))
            ->where('tokenable_id', (string) $target->getAuthIdentifier());
    }

    public function create(Authenticatable $actor, Authenticatable $target, array $input): array
    {
        $query = $this->query($actor, $target);
        $resolved = $this->providers->provider()->retrieveById($target->getAuthIdentifier());
        abort_unless($resolved && $resolved::class === $target::class, 422);
        $data = $this->validate($input);
        $lock = 'nova-mcp.tokens.'.hash('sha256', $this->providers->name().$target::class.$target->getAuthIdentifier());

        return Cache::lock($lock, 10)->block(5, function () use ($query, $actor, $target, $data) {
            return DB::transaction(function () use ($query, $actor, $target, $data) {
                if ((clone $query)->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count() >= config('nova-mcp.tokens.max_per_user')) {
                    throw ValidationException::withMessages(['name' => 'Active token limit reached. Revoke an existing token first.']);
                }
                $secret = bin2hex(random_bytes(32));
                $token = Token::query()->create($data + [
                    'provider' => $this->providers->name(), 'tokenable_type' => $this->providers->type($target),
                    'tokenable_id' => (string) $target->getAuthIdentifier(), 'token' => hash('sha256', $secret),
                ]);
                $this->audit->record('token.created', ['actor' => $actor->getAuthIdentifier(), 'token_id' => $token->id]);

                return ['token' => $token, 'plain_text_token' => 'nvm_'.$token->id.'_'.$secret];
            });
        });
    }

    public function change(Authenticatable $actor, Authenticatable $target, string $id, string $operation, array $input = []): array
    {
        return DB::transaction(function () use ($actor, $target, $id, $operation, $input) {
            $query = $this->query($actor, $target);
            $query->lockForUpdate();
            $token = $query->findOrFail($id);
            $plain = null;
            if ($operation === 'revoke') {
                $token->revoked_at = now();
            } else {
                abort_unless($token->active(), 422, 'Revoked or expired tokens cannot be edited or rotated.');
                if ($operation === 'rotate') {
                    $secret = bin2hex(random_bytes(32));
                    $token->token = hash('sha256', $secret);
                    $token->last_used_at = null;
                    $plain = 'nvm_'.$token->id.'_'.$secret;
                } else {
                    abort_unless($operation === 'update', 422);
                    $token->fill($this->validate($input));
                }
            }
            $token->save();
            $this->audit->record('token.'.$operation, ['actor' => $actor->getAuthIdentifier(), 'token_id' => $token->id]);

            return array_filter(['token' => $token, 'plain_text_token' => $plain], fn ($value) => $value !== null);
        });
    }

    private function validate(array $input): array
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'abilities' => ['required', 'array', 'min:1', 'max:8'],
            'abilities.*' => ['required', 'string', 'distinct', Rule::in(self::ABILITIES)],
            'expires_at' => ['present', 'nullable', 'date', 'after:now'],
        ])->validate();
        if (in_array('*', $data['abilities'], true) && count($data['abilities']) !== 1) {
            throw ValidationException::withMessages(['abilities' => 'Full access must be selected on its own.']);
        }
        if ($data['expires_at'] === null && ! config('nova-mcp.tokens.allow_non_expiring')) {
            throw ValidationException::withMessages(['expires_at' => 'An expiration is required.']);
        }
        if ($data['expires_at'] !== null && CarbonImmutable::parse($data['expires_at'])->isAfter(now()->addDays(config('nova-mcp.tokens.max_expiration_days')))) {
            throw ValidationException::withMessages(['expires_at' => 'Expiration exceeds the configured maximum.']);
        }

        return $data;
    }
}
