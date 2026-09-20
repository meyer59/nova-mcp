<?php

namespace NovaMcp\Tests\Feature;

use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use NovaMcp\Tests\Fixtures\Category;
use NovaMcp\Tests\Fixtures\CategoryResource;
use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\Fixtures\RecordResource;
use NovaMcp\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ResourceExposureTest extends TestCase
{
    public static function exposureCases(): array
    {
        return [
            'empty lists expose all' => [[], [], ['records', 'categories']],
            'empty include honors exclusions' => [[], [CategoryResource::class], ['records']],
            'include restricts exposure' => [[RecordResource::class], [], ['records']],
            'exclude takes precedence' => [[RecordResource::class, CategoryResource::class], [RecordResource::class], ['categories']],
            'exclude sole included resource' => [[RecordResource::class], [RecordResource::class], []],
            'exclude all resources' => [[], [RecordResource::class, CategoryResource::class], []],
            'unknown include exposes nothing' => [['App\\Nova\\Unknown'], [], []],
            'unknown exclude does not hide other resources' => [[], ['App\\Nova\\Unknown'], ['records', 'categories']],
        ];
    }

    #[DataProvider('exposureCases')]
    public function test_include_and_exclude_lists_work_together(array $included, array $excluded, array $expected): void
    {
        config(['nova-mcp.included_resources' => $included, 'nova-mcp.excluded_resources' => $excluded]);
        $resources = $this->payload($this->callTool($this->token()['plain_text_token'], 'resources'))['resources'];
        $this->assertSame($expected, array_column($resources, 'key'));
    }

    public function test_legacy_configuration_remains_an_additional_restriction(): void
    {
        $token = $this->token()['plain_text_token'];
        config(['nova-mcp.included_resources' => [RecordResource::class, CategoryResource::class]]);
        config(['nova-mcp.resources' => '*']);
        $this->assertSame(['records', 'categories'], array_column($this->payload($this->callTool($token, 'resources'))['resources'], 'key'));
        config(['nova-mcp.resources' => [RecordResource::class]]);
        $this->assertSame(['records'], array_column($this->payload($this->callTool($token, 'resources'))['resources'], 'key'));
        config(['nova-mcp.excluded_resources' => [RecordResource::class]]);
        $this->assertSame([], $this->payload($this->callTool($token, 'resources'))['resources']);
        config(['nova-mcp.resources' => [], 'nova-mcp.excluded_resources' => []]);
        $this->assertSame([], $this->payload($this->callTool($token, 'resources'))['resources']);
    }

    public function test_inclusion_does_not_bypass_nova_authorization(): void
    {
        config(['nova-mcp.included_resources' => [RecordResource::class]]);
        $token = $this->token($this->user('blocked'))['plain_text_token'];
        $this->assertSame([], $this->payload($this->callTool($token, 'resources'))['resources']);
        $this->callTool($token, 'describe', ['resource' => 'records'])->assertJsonPath('result.isError', true);
    }

    public function test_invalid_list_configuration_fails_closed(): void
    {
        $token = $this->token()['plain_text_token'];
        foreach (['included_resources', 'excluded_resources'] as $option) {
            foreach (['*', null, false] as $invalid) {
                config(['nova-mcp.'.$option => $invalid]);
                $this->assertSame([], $this->payload($this->callTool($token, 'resources'))['resources']);
            }
            config(['nova-mcp.'.$option => []]);
        }
    }

    public function test_excluded_resources_cannot_be_accessed_by_guessing_tool_arguments(): void
    {
        $record = Record::create(['name' => 'UNCHANGED', 'tenant_id' => 1]);
        $trashed = Record::create(['name' => 'TRASHED', 'tenant_id' => 1]);
        $trashed->delete();
        $token = $this->token()['plain_text_token'];
        config(['nova-mcp.included_resources' => [RecordResource::class], 'nova-mcp.excluded_resources' => [RecordResource::class]]);
        $operations = [
            'describe' => [], 'list' => [], 'get' => ['id' => (string) $record->id],
            'create' => ['fields' => ['name' => 'injected']],
            'update' => ['id' => (string) $record->id, 'fields' => ['name' => 'injected']],
            'delete' => ['id' => (string) $record->id], 'restore' => ['id' => (string) $trashed->id],
            'actions' => ['ids' => [(string) $record->id]],
            'run_action' => ['action' => 'rename', 'ids' => [(string) $record->id]],
            'relationships' => ['id' => (string) $record->id],
        ];
        foreach ($operations as $operation => $arguments) {
            $this->callTool($token, $operation, ['resource' => 'records'] + $arguments)
                ->assertJsonPath('result.isError', true)->assertJsonPath('result.content.0.text', json_encode(['code' => 'unavailable', 'message' => 'Resource or operation unavailable.']));
        }
        $this->assertSame(2, Record::withTrashed()->count());
        $this->assertSame('UNCHANGED', $record->fresh()->name);
        $this->assertNotSoftDeleted($record);
        $this->assertSoftDeleted($trashed);
    }

    public function test_exclusions_cover_relationship_reads_candidates_and_writes(): void
    {
        $category = Category::create(['name' => 'EXCLUDED', 'tenant_id' => 1]);
        $record = Record::create(['name' => 'PARENT', 'tenant_id' => 1, 'category_id' => $category->id]);
        $token = $this->token()['plain_text_token'];
        config(['nova-mcp.excluded_resources' => [CategoryResource::class]]);
        $args = ['resource' => 'records', 'id' => (string) $record->id];
        $this->assertArrayNotHasKey('category', $this->payload($this->callTool($token, 'get', $args))['fields']);
        $this->assertSame([], $this->payload($this->callTool($token, 'relationships', $args))['relationships']);
        foreach (['related', 'candidates'] as $mode) {
            $this->callTool($token, 'relationships', $args + ['relationship' => 'category', 'mode' => $mode])
                ->assertJsonPath('result.isError', true);
        }
        $this->callTool($token, 'update', $args + ['fields' => ['category' => null]])->assertJsonPath('result.isError', true);
        $this->assertSame($category->id, $record->fresh()->category_id);
    }

    public function test_excluded_documentation_is_never_evaluated(): void
    {
        Nova::$resources = [ExcludedDocumentedResource::class];
        config(['nova-mcp.included_resources' => [ExcludedDocumentedResource::class], 'nova-mcp.excluded_resources' => [ExcludedDocumentedResource::class]]);
        $token = $this->token()['plain_text_token'];
        $this->assertSame([], $this->payload($this->callTool($token, 'resources'))['resources']);
        $this->callTool($token, 'describe', ['resource' => 'records'])->assertJsonPath('result.isError', true);
    }
}

class ExcludedDocumentedResource extends RecordResource
{
    public static function mcpDescription(NovaRequest $request): string
    {
        throw new \LogicException('Excluded documentation must not be evaluated.');
    }
}
