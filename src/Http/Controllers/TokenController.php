<?php

namespace NovaMcp\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Laravel\Nova\Nova;
use NovaMcp\Auth\ProviderResolver;
use NovaMcp\NovaMcp;
use NovaMcp\Tokens\TokenService;

class TokenController
{
    public function __invoke(Request $request, TokenService $tokens, ProviderResolver $providers, ?string $id = null, ?string $operation = null): mixed
    {
        Validator::make($request->query(), [
            'owner' => ['sometimes', 'string', 'max:160', 'prohibited_if:scope,manageable'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'scope' => ['sometimes', 'in:manageable'],
            'before' => ['sometimes', 'integer', 'min:1'],
        ])->validate();
        $actor = Nova::user($request);
        $target = null;
        if ($request->query('scope') !== 'manageable') {
            $target = $request->query('owner') === null ? $actor : $providers->provider()->retrieveById($request->query('owner'));
            abort_unless($target && NovaMcp::canManage($actor, $target), 404);
        } else {
            abort_unless($request->isMethod('GET'), 422);
        }
        if ($request->isMethod('GET')) {
            if ($target) {
                $page = $tokens->query($actor, $target)->latest('id')->simplePaginate(50);
                $listing = ['tokens' => $page->items(), 'page' => $page->currentPage(), 'has_more' => $page->hasMorePages()];
            } else {
                $listing = $tokens->manageable($actor, $request->query('before'));
            }

            return response()->json($listing + [
                'endpoint' => url(config('nova-mcp.path')),
                'default_expiration_days' => config('nova-mcp.tokens.default_expiration_days'),
                'max_expiration_days' => config('nova-mcp.tokens.max_expiration_days'),
                'allow_non_expiring' => config('nova-mcp.tokens.allow_non_expiring'),
            ]);
        }
        if ($id === null) {
            return response()->json($tokens->create($actor, $target, $request->all()), 201);
        }

        return response()->json($tokens->change($actor, $target, $id, $operation ?? 'update', $request->all()));
    }
}
