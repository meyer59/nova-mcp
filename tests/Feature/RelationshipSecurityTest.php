<?php

namespace NovaMcp\Tests\Feature;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\ID;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use NovaMcp\Tests\Fixtures\Category;
use NovaMcp\Tests\Fixtures\CategoryResource;
use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\Fixtures\RecordResource;
use NovaMcp\Tests\TestCase;

class RelationshipSecurityTest extends TestCase
{
    public function test_many_to_many_reads_preserve_membership_and_related_tenant_scope(): void
    {
        Schema::create('category_record', function (Blueprint $table) {
            $table->unsignedBigInteger('record_id');
            $table->unsignedBigInteger('category_id');
        });
        Nova::$resources = [UnscopedRelationResource::class, ScopedCategoryResource::class];
        $foreign = ScopedCategory::create(['name' => 'Foreign', 'tenant_id' => 2]);
        $member = ScopedCategory::create(['name' => 'Member', 'tenant_id' => 1]);
        ScopedCategory::create(['name' => 'Not a member', 'tenant_id' => 1]);
        $record = UnscopedRelationRecord::create(['name' => 'Parent', 'tenant_id' => 1]);
        $record->categories()->attach([$foreign->id, $member->id]);
        $token = $this->token()['plain_text_token'];
        $rows = $this->payload($this->callTool($token, 'relationships', ['resource' => 'records', 'id' => (string) $record->id, 'relationship' => 'categories']))['resources'];
        $this->assertSame([(string) $member->id], array_column($rows, 'id'));
    }

    public function test_relationships_cannot_remove_related_model_global_scopes(): void
    {
        Nova::$resources = [UnscopedRelationResource::class, ScopedCategoryResource::class];
        $foreign = ScopedCategory::create(['name' => 'Foreign', 'tenant_id' => 2]);
        $own = ScopedCategory::create(['name' => 'Own', 'tenant_id' => 1]);
        $record = UnscopedRelationRecord::create(['name' => 'Parent', 'tenant_id' => 1, 'category_id' => $foreign->id]);
        $token = $this->token()['plain_text_token'];
        $args = ['resource' => 'records', 'id' => (string) $record->id, 'relationship' => 'category'];

        $this->assertSame([], $this->payload($this->callTool($token, 'relationships', $args))['resources']);
        $record->update(['category_id' => $own->id]);
        $this->assertSame((string) $own->id, $this->payload($this->callTool($token, 'relationships', $args))['resources'][0]['id']);
    }

    public function test_related_fields_and_policies_use_the_related_request_context(): void
    {
        Nova::$resources = [UnscopedRelationResource::class, ScopedCategoryResource::class];
        $category = ScopedCategory::create(['name' => 'Private name', 'tenant_id' => 1]);
        $record = UnscopedRelationRecord::create(['name' => 'Parent', 'tenant_id' => 1, 'category_id' => $category->id]);
        $token = $this->token()['plain_text_token'];
        $args = ['resource' => 'records', 'id' => (string) $record->id, 'relationship' => 'category'];
        foreach (['related', 'candidates'] as $mode) {
            $row = $this->payload($this->callTool($token, 'relationships', $args + ['mode' => $mode]))['resources'][0];
            $this->assertArrayNotHasKey('name', $row['fields']);
        }
    }

    public function test_relatable_callbacks_cannot_bypass_global_scopes_for_candidates_or_writes(): void
    {
        Nova::$resources = [UnscopedRelationResource::class, ScopedCategoryResource::class];
        $foreign = ScopedCategory::create(['name' => 'Foreign', 'tenant_id' => 2]);
        $own = ScopedCategory::create(['name' => 'Own', 'tenant_id' => 1]);
        $record = UnscopedRelationRecord::create(['name' => 'Parent', 'tenant_id' => 1, 'category_id' => $own->id]);
        $token = $this->token()['plain_text_token'];
        $args = ['resource' => 'records', 'id' => (string) $record->id];
        $rows = $this->payload($this->callTool($token, 'relationships', $args + ['relationship' => 'category', 'mode' => 'candidates']))['resources'];
        $this->assertSame([(string) $own->id], array_column($rows, 'id'));
        $this->callTool($token, 'update', $args + ['fields' => ['category' => (string) $foreign->id]])->assertJsonPath('result.isError', true);
        $this->assertSame($own->id, $record->fresh()->category_id);
    }
}

class ScopedCategory extends Category
{
    protected $table = 'categories';

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', fn ($query) => $query->where('tenant_id', auth()->user()?->tenant_id ?? 1));
    }
}

class ScopedCategoryResource extends CategoryResource
{
    public static $model = ScopedCategory::class;

    public static function indexQuery(NovaRequest $request, Builder $query)
    {
        return $query; // This application relies on the model's global tenant scope.
    }

    public function fields(NovaRequest $request): array
    {
        return [ID::make(), Text::make('Name')->canSee(fn () => request()->route('resource') !== 'categories')];
    }

    public static function relatableQuery(NovaRequest $request, Builder $query)
    {
        return $query->withoutGlobalScopes();
    }
}

class UnscopedRelationRecord extends Record
{
    protected $table = 'records';

    public function category()
    {
        return $this->belongsTo(ScopedCategory::class)->withoutGlobalScopes();
    }

    public function categories()
    {
        return $this->belongsToMany(ScopedCategory::class, 'category_record', 'record_id', 'category_id')->withoutGlobalScopes();
    }
}

class UnscopedRelationResource extends RecordResource
{
    public static $model = UnscopedRelationRecord::class;

    public function fields(NovaRequest $request): array
    {
        return [ID::make(), BelongsTo::make('Category', 'category', ScopedCategoryResource::class)->nullable(), BelongsToMany::make('Categories', 'categories', ScopedCategoryResource::class)];
    }
}
