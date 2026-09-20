<?php

namespace NovaMcp\Tests\Feature;

use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\LensRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Lenses\Lens;
use Laravel\Nova\Nova;
use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\Fixtures\RecordResource;
use NovaMcp\Tests\TestCase;

class LensSecurityTest extends TestCase
{
    public function test_lens_or_conditions_cannot_escape_the_resource_scope(): void
    {
        Nova::$resources = [LensSecurityResource::class];
        $own = Record::create(['name' => 'OWN', 'tenant_id' => 1]);
        Record::create(['name' => 'FOREIGN', 'tenant_id' => 2]);
        $token = $this->token()['plain_text_token'];
        $rows = $this->payload($this->callTool($token, 'list', ['resource' => 'records', 'lens' => 'expanded']))['resources'];
        $this->assertSame([(string) $own->id], array_column($rows, 'id'));
    }

    public function test_lens_fields_use_the_lens_request_for_global_helpers(): void
    {
        Nova::$resources = [LensSecurityResource::class];
        Record::create(['name' => 'OWN', 'tenant_id' => 1, 'secret' => 'private']);
        $token = $this->token()['plain_text_token'];
        $rows = $this->payload($this->callTool($token, 'list', ['resource' => 'records', 'lens' => 'expanded']))['resources'];
        $this->assertArrayNotHasKey('secret', $rows[0]['fields']);
    }

    public function test_aggregate_and_union_lenses_fail_closed(): void
    {
        Nova::$resources = [LensSecurityResource::class];
        Record::create(['name' => 'OWN', 'tenant_id' => 1]);
        Record::create(['name' => 'FOREIGN', 'tenant_id' => 2]);
        $token = $this->token()['plain_text_token'];
        foreach (['aggregate', 'union'] as $lens) {
            $this->callTool($token, 'list', ['resource' => 'records', 'lens' => $lens])->assertJsonPath('result.isError', true);
        }
    }
}

class LensSecurityResource extends RecordResource
{
    public function lenses(NovaRequest $request): array
    {
        return [new ExpandedLens, new AggregateLens, new UnionLens];
    }
}

class ExpandedLens extends Lens
{
    public static function query(LensRequest $request, $query)
    {
        return Record::withoutGlobalScopes()->where('tenant_id', 2)->orWhere('tenant_id', 1);
    }

    public function fields(NovaRequest $request): array
    {
        return [ID::make(), Text::make('Name'), Text::make('Secret')->canSee(fn () => ! request() instanceof LensRequest)];
    }

    public function uriKey(): string
    {
        return 'expanded';
    }
}

class AggregateLens extends ExpandedLens
{
    public static function query(LensRequest $request, $query)
    {
        return Record::withoutGlobalScopes()->selectRaw('MIN(id) AS id, MAX(name) AS name');
    }

    public function uriKey(): string
    {
        return 'aggregate';
    }
}

class UnionLens extends ExpandedLens
{
    public static function query(LensRequest $request, $query)
    {
        return $query->union(Record::withoutGlobalScopes()->where('tenant_id', 2));
    }

    public function uriKey(): string
    {
        return 'union';
    }
}
