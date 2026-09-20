<?php

namespace NovaMcp\Tests\Feature;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\SanctumServiceProvider;
use NovaMcp\Tests\Fixtures\ApiUser;
use NovaMcp\Tests\Fixtures\NovaUser;
use NovaMcp\Tests\TestCase;

class SanctumTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [SanctumServiceProvider::class]);
    }

    public function test_sanctum_and_nova_tokens_cannot_be_used_interchangeably(): void
    {
        (require __DIR__.'/../../vendor/laravel/sanctum/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php')->up();
        config(['auth.providers.users.model' => ApiUser::class, 'sanctum.guard' => [], 'nova.guard' => 'nova',
            'auth.guards.nova' => ['driver' => 'session', 'provider' => 'nova_users'],
            'auth.providers.nova_users' => ['driver' => 'eloquent', 'model' => NovaUser::class]]);
        Route::get('/test-api', fn () => ['id' => auth()->id(), 'name' => auth()->user()->name])->middleware('auth:sanctum');
        $api = ApiUser::create(['name' => 'api', 'tenant_id' => 9]);
        $nova = NovaUser::create(['name' => 'nova', 'tenant_id' => 1]);
        $apiToken = $api->createToken('Existing API', ['read'])->plainTextToken;
        $novaToken = $this->token($nova)['plain_text_token'];
        $this->rpc($apiToken, 'ping')->assertUnauthorized();
        $this->rpc($novaToken, 'ping')->assertOk();
        $this->withHeaders(['Authorization' => 'Bearer '.$apiToken])->getJson('/test-api')->assertOk()->assertJsonPath('name', 'api');
        // Explicitly clear the testing kernel's cached guard between simulated requests.
        Auth::forgetGuards();
        $this->withHeaders(['Authorization' => 'Bearer '.$novaToken])->getJson('/test-api')->assertUnauthorized();
        $this->assertSame('session', config('auth.guards.nova.driver'));
        $this->assertSame(1, $api->tokens()->count());
    }
}
