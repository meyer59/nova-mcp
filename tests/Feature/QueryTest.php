<?php

namespace NovaMcp\Tests\Feature;

use NovaMcp\Tests\Fixtures\Category;
use NovaMcp\Tests\Fixtures\NameFilter;
use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\Fixtures\RecordResource;
use NovaMcp\Tests\TestCase;

class QueryTest extends TestCase
{
    public function test_default_pagination_respects_a_reduced_configured_limit(): void
    {
        config(['nova-mcp.max_page_size' => 2]);
        for ($i = 0; $i < 3; $i++) {
            Record::create(['name' => 'ROW '.$i, 'tenant_id' => 1]);
            Category::create(['name' => 'Category '.$i, 'tenant_id' => 1]);
        }
        $token = $this->token()['plain_text_token'];
        $list = $this->payload($this->callTool($token, 'list', ['resource' => 'records']));
        $this->assertCount(2, $list['resources']);
        $this->assertTrue($list['has_more']);
        $related = $this->payload($this->callTool($token, 'relationships', ['resource' => 'records', 'id' => (string) Record::first()->id, 'relationship' => 'category', 'mode' => 'candidates']));
        $this->assertCount(2, $related['resources']);
        $this->assertTrue($related['has_more']);
    }

    public function test_search_filters_lenses_and_pagination(): void
    {
        Record::create(['name' => 'FIRST', 'tenant_id' => 1, 'secret' => 'hidden']);
        Record::create(['name' => 'FIRST', 'tenant_id' => 2]);
        Record::create(['name' => 'SECOND', 'tenant_id' => 1]);
        $token = $this->token()['plain_text_token'];
        foreach ([['search' => 'FIRST'], ['filters' => [NameFilter::class => 'FIRST']], ['lens' => 'first']] as $input) {
            $result = $this->payload($this->callTool($token, 'list', ['resource' => 'records'] + $input));
            $this->assertCount(1, $result['resources'], json_encode($input));
            $this->assertSame('FIRST', $result['resources'][0]['fields']['name']);
            $this->assertArrayNotHasKey('secret', $result['resources'][0]['fields']);
        }
        $page = $this->payload($this->callTool($token, 'list', ['resource' => 'records', 'per_page' => 1]));
        $this->assertCount(1, $page['resources']);
        $this->assertTrue($page['has_more']);
        $this->callTool($token, 'list', ['resource' => 'records', 'filters' => ['unknown' => 'FIRST']])->assertJsonPath('result.isError', true);
        $this->callTool($token, 'list', ['resource' => 'records', 'per_page' => 101])->assertJsonPath('result.isError', true);
    }

    public function test_relationship_reads_and_writes_apply_exposure_scopes_and_relatable_queries(): void
    {
        $allowed = Category::create(['name' => 'Allowed', 'tenant_id' => 1]);
        $denied = Category::create(['name' => 'NOT RELATABLE', 'tenant_id' => 1]);
        $foreign = Category::create(['name' => 'Foreign', 'tenant_id' => 2]);
        $record = Record::create(['name' => 'OWN', 'tenant_id' => 1, 'category_id' => $allowed->id]);
        $token = $this->token()['plain_text_token'];
        $args = ['resource' => 'records', 'id' => (string) $record->id];
        $data = $this->payload($this->callTool($token, 'relationships', $args + ['relationship' => 'category']));
        $this->assertSame((string) $allowed->id, $data['resources'][0]['id']);
        $data = $this->payload($this->callTool($token, 'relationships', $args + ['relationship' => 'category', 'mode' => 'candidates']));
        $this->assertCount(1, $data['resources']);
        foreach ([$denied, $foreign] as $category) {
            $this->callTool($token, 'update', $args + ['fields' => ['name' => 'OWN', 'category' => (string) $category->id]])->assertJsonPath('result.isError', true);
        }
        $this->payload($this->callTool($token, 'update', $args + ['fields' => ['name' => 'OWN', 'category' => (string) $allowed->id]]));
        config(['nova-mcp.resources' => [RecordResource::class]]);
        $schema = $this->payload($this->callTool($token, 'describe', $args));
        $this->assertSame([], $schema['relationships']);
        $this->assertArrayNotHasKey('category', $schema['fields']);
    }

    public function test_relationship_ability_is_required_for_relationship_writes(): void
    {
        $category = Category::create(['name' => 'Allowed', 'tenant_id' => 1]);
        $record = Record::create(['name' => 'OWN', 'tenant_id' => 1]);
        $token = $this->token(null, ['read', 'update'])['plain_text_token'];
        $args = ['resource' => 'records', 'id' => (string) $record->id];
        $this->callTool($token, 'update', $args + ['fields' => ['name' => 'OWN', 'category' => (string) $category->id]])->assertJsonPath('result.isError', true);
        $this->assertNull($record->fresh()->category_id);
    }
}
