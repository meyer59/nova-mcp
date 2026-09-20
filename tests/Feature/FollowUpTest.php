<?php

namespace NovaMcp\Tests\Feature;

use Composer\InstalledVersions;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\BooleanGroup;
use Laravel\Nova\Fields\Heading;
use Laravel\Nova\Fields\KeyValue;
use Laravel\Nova\Fields\MultiSelect;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Status;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Fields\Trix;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use NovaMcp\Nova\UpdateState;
use NovaMcp\NovaMcp;
use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\Fixtures\RecordPolicy;
use NovaMcp\Tests\Fixtures\RecordResource;
use NovaMcp\Tests\Fixtures\UnsupportedField;
use NovaMcp\Tests\TestCase;

class FollowUpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FollowUpResource::$makeFields = fn () => [Text::make('Name'), Text::make('Custom')];
        FollowUpResource::$model = Record::class;
        FollowUpAction::$hiddenError = false;
        FollowUpAction::$received = [];
        Nova::$resources = [FollowUpResource::class];
        Gate::policy(FollowUpRecord::class, RecordPolicy::class);
    }

    private function update(Record $record, array $fields)
    {
        return $this->callTool($this->token()['plain_text_token'], 'update', [
            'resource' => 'records', 'id' => (string) $record->id, 'fields' => $fields,
        ]);
    }

    private function error($response): array
    {
        $response->assertJsonPath('result.isError', true);

        return json_decode($response->json('result.content.0.text'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_timestamp_boolean_null_and_true_are_validation_only(): void
    {
        FollowUpResource::$model = FollowUpRecord::class;
        FollowUpResource::$makeFields = fn ($resource) => [
            Text::make('Name')->rules('required'),
            Boolean::make('Deactivated', 'custom')->rules('required', 'boolean')
                ->trueValue($resource->model()->custom ?? now())->falseValue(null)->nullable(),
        ];
        $record = FollowUpRecord::create(['name' => 'Original', 'tenant_id' => 1, 'custom' => null]);
        $this->payload($this->update($record, ['name' => 'Changed']));
        $this->assertNull($record->fresh()->custom);
        $this->payload($this->update($record, ['custom' => true]));
        $stored = $record->fresh()->getRawOriginal('custom');
        $this->assertNotNull($stored);
        $this->payload($this->update($record, ['name' => 'Again']));
        $this->assertSame($stored, $record->fresh()->getRawOriginal('custom'));
        $this->assertSame('validation', $this->error($this->update($record, ['custom' => null]))['code']);
        $this->assertSame($stored, $record->fresh()->getRawOriginal('custom'));
        $this->payload($this->update($record, ['custom' => false]));
        $this->assertNull($record->fresh()->custom);
    }

    public function test_classic_boolean_null_and_numeric_states(): void
    {
        FollowUpResource::$makeFields = fn () => [
            Text::make('Name'),
            Boolean::make('Custom')->values(1, 0)->rules('required', 'boolean')->nullable(),
        ];
        $record = Record::create(['name' => 'Original', 'tenant_id' => 1, 'custom' => null]);
        foreach ([null, '0', '1'] as $value) {
            $record->update(['custom' => $value]);
            $this->payload($this->update($record, ['name' => 'Changed']));
            $this->assertSame($value, $record->fresh()->custom);
        }
        $this->payload($this->update($record, ['custom' => false]));
        $this->assertSame('0', $record->fresh()->custom);
        $this->payload($this->update($record, ['custom' => true]));
        $this->assertSame('1', $record->fresh()->custom);
    }

    public function test_unselected_boolean_is_not_invented(): void
    {
        FollowUpResource::$makeFields = fn () => [Boolean::make('Custom')->falseValue(null)];
        $resource = new FollowUpResource(new Record(['name' => 'Only selected column']));
        $request = NovaRequest::create('/');
        $this->assertArrayNotHasKey('custom', app(UpdateState::class)->forFields($resource, $request));
    }

    public function test_describe_reports_only_known_unsupported_requirements(): void
    {
        FollowUpResource::$makeFields = fn () => [
            Text::make('Name'),
            (new UnsupportedField('Custom'))->rules('required'),
            Text::make('Secret')->canSee(fn () => false)->rules('required'),
        ];
        $record = Record::create(['name' => 'Original', 'tenant_id' => 1, 'custom' => 'Stored']);
        $token = $this->token()['plain_text_token'];
        $result = $this->payload($this->callTool($token, 'describe', ['resource' => 'records', 'id' => (string) $record->id]));
        $this->assertSame([['field' => 'custom', 'reason' => 'required_unsupported_field']], $result['create_blockers']);
        $this->assertSame([], $result['update_blockers']);
        $this->assertStringNotContainsString('secret', json_encode($result));
        $record->update(['custom' => null]);
        $result = $this->payload($this->callTool($token, 'describe', ['resource' => 'records', 'id' => (string) $record->id]));
        $this->assertSame($result['create_blockers'], $result['update_blockers']);
        $result = $this->payload($this->callTool($this->token(abilities: ['read'])['plain_text_token'], 'describe', ['resource' => 'records', 'id' => (string) $record->id]));
        $this->assertArrayNotHasKey('create_blockers', $result);
        $this->assertArrayNotHasKey('update_blockers', $result);
    }

    public function test_actions_have_schema_coercion_and_safe_errors(): void
    {
        $record = Record::create(['name' => 'Original', 'tenant_id' => 1]);
        $token = $this->token()['plain_text_token'];
        $args = ['resource' => 'records', 'ids' => [(string) $record->id]];
        $actions = $this->payload($this->callTool($token, 'actions', $args));
        $schema = $actions['actions'][0]['schema'];
        $this->assertContains('reason', $schema['required']);
        $this->assertSame(10, $schema['properties']['reason']['maxLength']);
        $run = fn ($fields) => $this->callTool($token, 'run_action', $args + ['action' => 'follow-up-action', 'fields' => $fields]);
        $this->assertSame(['This field is required.'], $this->error($run([]))['fields']['reason']);
        $this->assertSame(['The value exceeds the allowed maximum.'], $this->error($run(['reason' => 'much too long']))['fields']['reason']);
        $this->payload($run(['reason' => 'Fine', 'approved' => '1']));
        $this->assertTrue(FollowUpAction::$received['approved']);
        FollowUpAction::$hiddenError = true;
        $error = $this->error($run(['reason' => 'Fine']));
        $this->assertArrayHasKey('_', $error['fields']);
        $this->assertStringNotContainsString('secret', json_encode($error));
    }

    public function test_schema_rule_objects_arrays_and_safe_dependencies(): void
    {
        FollowUpResource::$makeFields = fn () => [
            Text::make('Name')->rules(['nullable', 'string', 'size:3', ''])->dependsOn(['custom', 'secret'], fn () => null),
            Text::make('Custom')->rules([Rule::in(['a|b', 'xyz'])]),
            Text::make('Secret')->canSee(fn () => false),
        ];
        $describe = fn () => $this->payload($this->callTool($this->token()['plain_text_token'], 'describe', ['resource' => 'records']))['create_fields'];
        $fields = $describe();
        $this->assertSame(3, $fields['name']['minLength']);
        $this->assertSame(3, $fields['name']['maxLength']);
        $this->assertArrayNotHasKey('x-nova-rules', $fields['name']);
        $this->assertSame(['custom'], $fields['name']['x-nova-depends-on']);
        $this->assertContains('a|b', $fields['custom']['enum']);
        FollowUpResource::$makeFields = fn () => [
            Text::make('Name')->rules([Rule::enum(FollowUpChoice::class)->only([FollowUpChoice::First])]),
            Text::make('Custom')->rules([Rule::unique('private_table', 'private_column'), Rule::exists('private_table')]),
        ];
        $fields = $describe();
        $this->assertSame(['first'], $fields['name']['enum']);
        $this->assertSame(['server'], $fields['custom']['x-nova-rules']);
        $this->assertStringNotContainsString('private_table', json_encode($fields));
    }

    public function test_date_and_ip_hints_do_not_claim_arbitrary_dates_are_iso(): void
    {
        FollowUpResource::$makeFields = fn () => [Text::make('Name')->rules('date'), Text::make('Custom')->rules('nullable', 'ip')];
        $result = $this->payload($this->callTool($this->token()['plain_text_token'], 'describe', ['resource' => 'records']));
        $this->assertArrayNotHasKey('format', $result['create_fields']['name']);
        $this->assertSame('ipv4', $result['create_fields']['custom']['anyOf'][0]['format']);
        $this->assertSame(['type' => 'null'], $result['create_fields']['custom']['anyOf'][2]);
    }

    public function test_collection_adapters_round_trip_and_bound_inputs(): void
    {
        FollowUpResource::$model = FollowUpJsonRecord::class;
        Gate::policy(FollowUpJsonRecord::class, RecordPolicy::class);
        $record = FollowUpJsonRecord::create(['name' => 'Original', 'tenant_id' => 1]);
        foreach ([
            [fn () => MultiSelect::make('Custom')->options(['red' => 'Red', 'blue' => 'Blue']), ['red'], [['unknown'], ['red', 'red'], array_fill(0, 101, 'red')], 'array'],
            [fn () => BooleanGroup::make('Custom')->options(['enabled' => 'Enabled']), ['enabled' => true], [['unknown' => true], ['enabled' => 'true']], 'object'],
            [fn () => KeyValue::make('Custom'), ['label' => 'Value', 'enabled' => true, 'count' => 3], [['label' => ['nested']], ['label' => str_repeat('x', 1001)], array_fill_keys(array_map(fn ($i) => 'k'.$i, range(1, 101)), 'v')], 'object'],
        ] as [$field, $valid, $invalid, $type]) {
            FollowUpResource::$makeFields = fn () => [Text::make('Name'), $field()];
            $this->payload($this->update($record, ['custom' => $valid]));
            $this->assertSame($valid, $record->fresh()->custom);
            $this->payload($this->update($record, ['name' => 'Changed']));
            $this->assertSame($valid, $record->fresh()->custom);
            $result = $this->payload($this->callTool($this->token()['plain_text_token'], 'get', ['resource' => 'records', 'id' => (string) $record->id]));
            $this->assertSame($valid, $result['fields']['custom']);
            $result = $this->payload($this->callTool($this->token()['plain_text_token'], 'describe', ['resource' => 'records']));
            $this->assertContains($type, $result['create_fields']['custom']['type']);
            foreach ($invalid as $value) {
                $this->assertSame('validation', $this->error($this->update($record, ['custom' => $value]))['code']);
                $this->assertSame($valid, $record->fresh()->custom);
            }
        }
    }

    public function test_collection_nulls_obey_nova_validation_and_empty_values_round_trip(): void
    {
        FollowUpResource::$model = FollowUpJsonRecord::class;
        Gate::policy(FollowUpJsonRecord::class, RecordPolicy::class);
        $record = FollowUpJsonRecord::create(['name' => 'Original', 'tenant_id' => 1, 'custom' => ['red']]);
        foreach ([
            fn () => MultiSelect::make('Custom')->options(['red' => 'Red']),
            fn () => BooleanGroup::make('Custom')->options(['enabled' => 'Enabled']),
            fn () => KeyValue::make('Custom'),
        ] as $make) {
            FollowUpResource::$makeFields = fn () => [$make()->rules('required')];
            $this->assertSame(['This field is required.'], $this->error($this->update($record, ['custom' => null]))['fields']['custom']);
            FollowUpResource::$makeFields = fn () => [$make()->nullable()];
            $this->payload($this->update($record, ['custom' => []]));
            $this->assertSame([], $record->fresh()->custom);
            $this->payload($this->update($record, ['custom' => null]));
            $this->assertNull($record->fresh()->custom);
        }
    }

    public function test_restricted_key_value_and_file_enabled_trix_remain_readonly(): void
    {
        $record = Record::create(['name' => 'Original', 'tenant_id' => 1]);
        foreach ([
            KeyValue::make('Custom')->disableEditingKeys(),
            KeyValue::make('Custom')->disableAddingRows(),
            KeyValue::make('Custom')->disableDeletingRows(),
            Trix::make('Custom')->withFiles(),
        ] as $field) {
            FollowUpResource::$makeFields = fn () => [Text::make('Name'), $field];
            $this->error($this->update($record, ['custom' => 'blocked']));
        }
        FollowUpResource::$makeFields = fn () => [Trix::make('Custom')];
        $this->payload($this->update($record, ['custom' => '<p>Hello</p>']));
        $this->assertSame('<p>Hello</p>', $record->fresh()->custom);
    }

    public function test_bulk_revocation_is_scoped_idempotent_and_audited(): void
    {
        $owner = $this->user();
        $one = $this->token($owner);
        $two = $this->token($owner);
        $other = $this->token();
        $foreign = $one['token']->replicate();
        $foreign->provider = 'different-provider';
        $foreign->token = hash('sha256', 'foreign-test-token');
        $foreign->save();
        $differentType = $one['token']->replicate();
        $differentType->tokenable_type = 'DifferentUser';
        $differentType->token = hash('sha256', 'different-type-test-token');
        $differentType->save();
        Log::spy();
        Log::shouldReceive('channel')->andReturnSelf();
        $this->assertSame(2, NovaMcp::revokeTokensFor($owner, 'password_reset'));
        $this->assertSame(0, NovaMcp::revokeTokensFor($owner, 'password_reset'));
        $this->assertTrue($other['token']->fresh()->active());
        $this->assertTrue($foreign->fresh()->active());
        $this->assertTrue($differentType->fresh()->active());
        $this->callTool($one['plain_text_token'], 'resources')->assertUnauthorized();
        $this->callTool($two['plain_text_token'], 'resources')->assertUnauthorized();
        Log::shouldHaveReceived('info')->with('nova-mcp.token.revoked_bulk', \Mockery::on(fn ($metadata) => $metadata['owner_id'] === (string) $owner->id && $metadata['count'] === 2 && $metadata['reason'] === 'password_reset'
        ))->once();
    }

    public function test_readonly_display_fields_and_custom_subclasses_cannot_be_written(): void
    {
        FollowUpResource::$makeFields = fn () => [
            Status::make('Name'),
            Badge::make('Custom')->map(['stored' => 'info']),
            new FollowUpCustomText('Secret'),
            Heading::make('Display only'),
        ];
        $record = Record::create(['name' => 'Original', 'tenant_id' => 1, 'custom' => 'stored', 'secret' => 'hidden']);
        $token = $this->token()['plain_text_token'];
        $result = $this->payload($this->callTool($token, 'describe', ['resource' => 'records']));
        $this->assertTrue($result['fields']['name']['readOnly']);
        $this->assertTrue($result['fields']['custom']['readOnly']);
        $this->assertSame([], $result['create_fields']);
        $result = $this->payload($this->callTool($token, 'get', ['resource' => 'records', 'id' => (string) $record->id]));
        $this->assertSame('Original', $result['fields']['name']);
        $this->assertSame('stored', $result['fields']['custom']);
        $this->assertArrayNotHasKey('secret', $result['fields']);
        foreach (['name', 'custom', 'secret'] as $key) {
            $this->error($this->update($record, [$key => 'Must not save']));
        }
        $this->assertSame('Original', $record->fresh()->name);
    }

    public function test_collections_work_on_create_and_actions_and_filter_unknown_read_options(): void
    {
        FollowUpResource::$model = FollowUpJsonRecord::class;
        Gate::policy(FollowUpJsonRecord::class, RecordPolicy::class);
        FollowUpResource::$makeFields = fn () => [Text::make('Name'), MultiSelect::make('Custom')->options([1 => 'One', 2 => 'Two'])];
        $token = $this->token()['plain_text_token'];
        $this->payload($this->callTool($token, 'create', ['resource' => 'records', 'fields' => ['name' => 'Created', 'custom' => ['1']]]));
        $record = FollowUpJsonRecord::firstOrFail();
        $this->assertSame([1], $record->custom);
        $this->payload($this->callTool($token, 'run_action', [
            'resource' => 'records', 'ids' => [(string) $record->id], 'action' => 'follow-up-action',
            'fields' => ['reason' => 'Valid', 'options' => ['red']],
        ]));
        $this->assertSame(['red'], FollowUpAction::$received['options']);
        $record->update(['custom' => [1, 999]]);
        $result = $this->payload($this->callTool($token, 'get', ['resource' => 'records', 'id' => (string) $record->id]));
        $this->assertSame([1], $result['fields']['custom']);
    }

    public function test_collection_defaults_and_integer_enum_hints(): void
    {
        FollowUpResource::$model = FollowUpJsonRecord::class;
        Gate::policy(FollowUpJsonRecord::class, RecordPolicy::class);
        FollowUpResource::$makeFields = fn () => [
            Text::make('Name'),
            MultiSelect::make('Custom')->options(['red' => 'Red'])->default(['red'])->rules('required'),
            Number::make('Tenant', 'tenant_id')->rules([Rule::enum(FollowUpNumber::class)]),
        ];
        $token = $this->token()['plain_text_token'];
        $result = $this->payload($this->callTool($token, 'describe', ['resource' => 'records']));
        $this->assertSame(['red'], $result['create_fields']['custom']['default']);
        $this->assertNotContains('custom', $result['create_schema']['required']);
        $this->assertSame([1, 2], $result['create_fields']['tenant_id']['enum']);
        $this->payload($this->callTool($token, 'create', ['resource' => 'records', 'fields' => ['name' => 'Default', 'tenant_id' => 1]]));
        $this->assertSame(['red'], FollowUpJsonRecord::firstOrFail()->custom);
    }

    public function test_revocation_reason_is_a_bounded_event_code(): void
    {
        $token = $this->token();
        $this->expectException(\InvalidArgumentException::class);
        try {
            NovaMcp::revokeTokensFor($this->user(), "raw user text\nwith newlines");
        } finally {
            $this->assertTrue($token['token']->fresh()->active());
        }
    }

    public function test_protocol_reports_installed_package_version(): void
    {
        $response = $this->rpc($this->token()['plain_text_token'], 'initialize', [
            'protocolVersion' => '2025-06-18', 'capabilities' => new \stdClass,
            'clientInfo' => ['name' => 'Test', 'version' => '1'],
        ]);
        $version = InstalledVersions::isInstalled('meyer59/nova-mcp')
            ? InstalledVersions::getPrettyVersion('meyer59/nova-mcp') : 'unknown';
        $response->assertJsonPath('result.serverInfo.version', $version ?? 'unknown');
    }
}

enum FollowUpNumber: int
{
    case First = 1;
    case Second = 2;
}

enum FollowUpChoice: string
{
    case First = 'first';
    case Second = 'second';
}

class FollowUpResource extends RecordResource
{
    public static $makeFields;

    public function fields(NovaRequest $request): array
    {
        return (self::$makeFields)($this, $request);
    }

    public function actions(NovaRequest $request): array
    {
        return [new FollowUpAction];
    }
}

class FollowUpCustomText extends Text {}

class FollowUpRecord extends Record
{
    protected $table = 'records';

    protected $casts = ['custom' => 'datetime'];
}

class FollowUpJsonRecord extends Record
{
    protected $table = 'records';

    protected $casts = ['custom' => 'array'];
}

class FollowUpAction extends Action
{
    public static bool $hiddenError = false;

    public static array $received = [];

    public function fields(NovaRequest $request): array
    {
        return [
            Text::make('Reason')->rules('required', 'max:10'),
            Boolean::make('Approved')->rules('boolean'),
            MultiSelect::make('Options')->options(['red' => 'Red']),
            Text::make('Secret')->canSee(fn () => false)->rules('required'),
        ];
    }

    public function afterValidation(NovaRequest $request, Validator $validator)
    {
        if (self::$hiddenError) {
            $validator->errors()->add('secret', 'private secret must not escape');
        }
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        self::$received = $fields->getAttributes();

        return static::message('Done');
    }
}
