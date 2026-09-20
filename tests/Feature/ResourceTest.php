<?php

namespace NovaMcp\Tests\Feature;

use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\Fixtures\RecordResource;
use NovaMcp\Tests\TestCase;

class ResourceTest extends TestCase
{
    public function test_discovery_honors_exposure_and_policies(): void
    {
        $token = $this->token()['plain_text_token'];
        $this->assertSame('records', $this->payload($this->callTool($token, 'resources'))['resources'][0]['key']);
        config(['nova-mcp.resources' => []]);
        $this->assertSame([], $this->payload($this->callTool($token, 'resources'))['resources']);
        $this->callTool($token, 'describe', ['resource' => 'records'])->assertJsonPath('result.isError', true);
        config(['nova-mcp.resources' => [RecordResource::class]]);
        $blocked = $this->token($this->user('blocked'))['plain_text_token'];
        $this->assertSame([], $this->payload($this->callTool($blocked, 'resources'))['resources']);
    }

    public function test_list_and_get_preserve_tenant_scopes_and_field_visibility(): void
    {
        $own = Record::create(['name' => 'OWN', 'tenant_id' => 1, 'secret' => 'never expose', 'custom' => 'custom data']);
        $foreign = Record::create(['name' => 'OTHER', 'tenant_id' => 2]);
        Record::create(['name' => 'INVISIBLE', 'tenant_id' => 1]);
        $token = $this->token()['plain_text_token'];
        $rows = $this->payload($this->callTool($token, 'list', ['resource' => 'records']))['resources'];
        $this->assertCount(1, $rows);
        $this->assertSame('OWN', $rows[0]['fields']['name']);
        $this->assertArrayNotHasKey('secret', $rows[0]['fields']);
        $this->assertArrayNotHasKey('custom', $rows[0]['fields']);
        $this->callTool($token, 'get', ['resource' => 'records', 'id' => (string) $foreign->id])->assertJsonPath('result.isError', true);
        $detail = $this->payload($this->callTool($token, 'get', ['resource' => 'records', 'id' => (string) $own->id]));
        $this->assertSame('OWN', $detail['fields']['name']);
    }

    public function test_describe_is_contextual_and_unknown_fields_are_excluded(): void
    {
        $record = Record::create(['name' => 'OWN', 'tenant_id' => 1]);
        $token = $this->token()['plain_text_token'];
        $schema = $this->payload($this->callTool($token, 'describe', ['resource' => 'records', 'id' => (string) $record->id]));
        $this->assertArrayHasKey('name', $schema['update_fields']);
        foreach (['secret', 'custom', 'tenant_id', 'id'] as $key) {
            $this->assertArrayNotHasKey($key, $schema['update_fields']);
        }
        $reader = $this->token($this->user('reader'))['plain_text_token'];
        $schema = $this->payload($this->callTool($reader, 'describe', ['resource' => 'records', 'id' => (string) $record->id]));
        $this->assertArrayNotHasKey('update_fields', $schema);
        $this->assertArrayNotHasKey('create_fields', $schema);
    }

    public function test_mutations_use_nova_validation_fill_and_lifecycle(): void
    {
        $token = $this->token()['plain_text_token'];
        $this->callTool($token, 'create', ['resource' => 'records', 'fields' => []])->assertJsonPath('result.isError', true);
        $created = $this->payload($this->callTool($token, 'create', ['resource' => 'records', 'fields' => ['name' => 'created']]));
        $record = Record::findOrFail($created['id']);
        $this->assertSame('CREATED', $record->name);
        $this->assertSame(1, $record->tenant_id);
        $this->payload($this->callTool($token, 'update', ['resource' => 'records', 'id' => (string) $record->id, 'fields' => ['name' => 'updated']]));
        $this->assertSame('UPDATED', $record->fresh()->name);
        $this->payload($this->callTool($token, 'delete', ['resource' => 'records', 'id' => (string) $record->id]));
        $this->assertSoftDeleted($record);
        $this->payload($this->callTool($token, 'restore', ['resource' => 'records', 'id' => (string) $record->id]));
        $this->assertNotSoftDeleted($record);
    }

    public function test_mutations_cannot_escape_scopes_or_policy_or_mass_assign(): void
    {
        $own = Record::create(['name' => 'OWN', 'tenant_id' => 1]);
        $foreign = Record::create(['name' => 'OTHER', 'tenant_id' => 2]);
        $token = $this->token()['plain_text_token'];
        foreach (['secret', 'custom', 'tenant_id', 'resourceId', '_method'] as $key) {
            $this->callTool($token, 'update', ['resource' => 'records', 'id' => (string) $own->id, 'fields' => ['name' => 'bad', $key => 'injected']])->assertJsonPath('result.isError', true);
        }
        foreach (['update', 'delete', 'restore'] as $op) {
            $this->callTool($token, $op, ['resource' => 'records', 'id' => (string) $foreign->id])->assertJsonPath('result.isError', true);
        }
        $reader = $this->token($this->user('reader'))['plain_text_token'];
        $this->callTool($reader, 'update', ['resource' => 'records', 'id' => (string) $own->id, 'fields' => ['name' => 'bad']])->assertJsonPath('result.isError', true);
        $this->assertSame('OWN', $own->fresh()->name);
        $this->assertSame('OTHER', $foreign->fresh()->name);
    }

    public function test_actions_obey_visibility_permissions_and_scopes(): void
    {
        $own = Record::create(['name' => 'OWN', 'tenant_id' => 1]);
        $foreign = Record::create(['name' => 'OTHER', 'tenant_id' => 2]);
        $token = $this->token()['plain_text_token'];
        $args = ['resource' => 'records', 'ids' => [(string) $own->id]];
        $actions = $this->payload($this->callTool($token, 'actions', $args))['actions'];
        $this->assertCount(1, $actions);
        $this->payload($this->callTool($token, 'run_action', $args + ['action' => $actions[0]['key']]));
        $this->assertSame('ACTION', $own->fresh()->name);
        $this->callTool($token, 'run_action', ['resource' => 'records', 'ids' => [(string) $foreign->id], 'action' => $actions[0]['key']])->assertJsonPath('result.isError', true);
        $reader = $this->token($this->user('reader'))['plain_text_token'];
        $this->assertSame([], $this->payload($this->callTool($reader, 'actions', $args))['actions']);
        $this->assertSame('OTHER', $foreign->fresh()->name);
    }
}
