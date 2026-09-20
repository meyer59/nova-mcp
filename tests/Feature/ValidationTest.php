<?php

namespace NovaMcp\Tests\Feature;

use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Fields\Boolean;
use Laravel\Nova\Fields\File;
use Laravel\Nova\Fields\Number;
use Laravel\Nova\Fields\Password;
use Laravel\Nova\Fields\Select;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Controllers\ResourceUpdateController;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use Laravel\Nova\Resource;
use NovaMcp\Tests\Fixtures\Category;
use NovaMcp\Tests\Fixtures\CategoryResource;
use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\Fixtures\RecordResource;
use NovaMcp\Tests\Fixtures\UnsupportedField;
use NovaMcp\Tests\TestCase;

class ValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ValidationResource::$makeFields = fn () => [Text::make('Name')->rules('required', 'max:80'), Text::make('Custom')];
        ValidationResource::$afterValidationCallback = null;
        ValidationResource::$events = [];
        ValidationResource::$failAfterUpdate = false;
        Nova::$resources = [ValidationResource::class, CategoryResource::class];
    }

    private function updateRecord(Record $record, array $fields)
    {
        return $this->callTool($this->token()['plain_text_token'], 'update', [
            'resource' => 'records', 'id' => (string) $record->id, 'fields' => $fields,
        ]);
    }

    private function errors($response): array
    {
        $response->assertJsonPath('result.isError', true);

        return json_decode($response->json('result.content.0.text'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_omitted_required_fields_are_validated_without_becoming_writes(): void
    {
        ValidationResource::$makeFields = fn () => [
            Text::make('Name')->rules('required')->resolveUsing(fn () => 'FORMATTED')
                ->fillUsing(function ($request, $model, $attribute) {
                    if ($request->exists($attribute)) {
                        $model->{$attribute} = $request->input($attribute).' changed by fill';
                    }
                }),
            Text::make('Custom'),
        ];
        $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1]);
        $this->payload($this->updateRecord($record, ['custom' => 'Updated']));
        $this->assertSame('ORIGINAL', $record->fresh()->name);
        $this->assertSame('Updated', $record->fresh()->custom);
        $this->assertSame(ResourceUpdateController::class, app(ResourceUpdateController::class)::class);
        $this->assertSame(['validate', 'after-validation', 'fill:custom', 'before:custom', 'after'], ValidationResource::$events);
    }

    public function test_cross_field_and_dynamic_rules_see_existing_readonly_state(): void
    {
        ValidationResource::$makeFields = fn () => [
            Text::make('Custom')->rules(fn ($request) => $request->tenant_id == 1 ? ['declined_if:tenant_id,1'] : []),
            Number::make('Tenant', 'tenant_id')->readonly(),
        ];
        $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1]);
        $error = $this->errors($this->updateRecord($record, ['custom' => 'yes']));
        $this->assertSame('validation', $error['code']);
        $this->assertArrayHasKey('custom', $error['fields']);
        $this->assertNull($record->fresh()->custom);
    }

    public function test_an_unsupported_required_field_keeps_its_rules_and_existing_value(): void
    {
        ValidationResource::$makeFields = fn () => [Text::make('Name'), (new UnsupportedField('Custom'))->rules('required')];
        $record = Record::create(['name' => 'ORIGINAL', 'custom' => 'Stored', 'tenant_id' => 1]);
        $this->payload($this->updateRecord($record, ['name' => 'Updated']));
        $this->assertSame('Stored', $record->fresh()->custom);
        $record->update(['custom' => null]);
        $error = $this->errors($this->updateRecord($record, ['name' => 'Must not save']));
        $this->assertSame(['_'], array_keys($error['fields']));
        $this->assertSame('Updated', $record->fresh()->name);
        $this->errors($this->callTool($this->token()['plain_text_token'], 'create', [
            'resource' => 'records', 'fields' => ['name' => 'Missing required custom field'],
        ]));
        $this->assertSame(1, Record::count());
    }

    public function test_explicit_null_does_not_get_replaced_with_the_existing_value(): void
    {
        $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1]);
        $error = $this->errors($this->updateRecord($record, ['name' => null]));
        $this->assertSame(['This field is required.'], $error['fields']['name']);
        $this->assertSame('ORIGINAL', $record->fresh()->name);
    }

    public function test_visible_custom_errors_and_hidden_hook_errors_do_not_disclose_application_messages(): void
    {
        ValidationResource::$makeFields = fn () => [Text::make('Name')->rules('required', function ($attribute, $value, $fail) {
            $fail('Private API key: do-not-expose-this');
        })];
        ValidationResource::$afterValidationCallback = fn ($validator) => $validator->errors()->add('private_column', 'Another secret');
        $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1]);
        $error = $this->errors($this->updateRecord($record, ['name' => 'Updated']));
        $this->assertArrayHasKey('name', $error['fields']);
        $this->assertArrayHasKey('_', $error['fields']);
        $this->assertStringNotContainsString('do-not-expose-this', json_encode($error));
        $this->assertStringNotContainsString('private_column', json_encode($error));
        $this->assertStringNotContainsString('Another secret', json_encode($error));
    }

    public function test_validation_hooks_can_normalize_supplied_values_without_prefilling_writes(): void
    {
        ValidationResource::$afterValidationCallback = function () {
            request()->merge(['custom' => strtoupper(request('custom'))]);
        };
        $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1]);
        $this->payload($this->updateRecord($record, ['custom' => 'normalized']));
        $this->assertSame('NORMALIZED', $record->fresh()->custom);
        $this->assertSame('ORIGINAL', $record->fresh()->name);
        $this->assertContains('fill:custom', ValidationResource::$events);
    }

    public function test_nova_transaction_rolls_back_when_after_update_fails(): void
    {
        $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1]);
        ValidationResource::$failAfterUpdate = true;
        $error = $this->errors($this->updateRecord($record, ['name' => 'Updated']));
        $this->assertSame('failed', $error['code']);
        $this->assertSame('ORIGINAL', $record->fresh()->name);
        $this->assertDatabaseCount('action_events', 0);
    }

    public function test_unreadable_omitted_relationship_is_not_cleared(): void
    {
        Nova::$resources = [RecordResource::class, CategoryResource::class];
        $category = Category::create(['name' => 'PRIVATE', 'tenant_id' => 2]);
        $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1, 'category_id' => $category->id]);
        // Nova may reject an existing unrelatable assignment; do not bypass that rule.
        $this->errors($this->updateRecord($record, ['name' => 'Updated']));
        $this->assertSame('ORIGINAL', $record->fresh()->name);
        $this->assertSame($category->id, $record->fresh()->category_id);
    }

    public function test_unique_resource_id_placeholder_still_uses_nova_formatting(): void
    {
        ValidationResource::$makeFields = fn () => [
            Text::make('Name')->rules('required')->updateRules('unique:records,name,{{resourceId}}'),
            Text::make('Custom'),
        ];
        $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1]);
        $this->payload($this->updateRecord($record, ['custom' => 'Updated']));
        Record::create(['name' => 'TAKEN', 'tenant_id' => 1]);
        $error = $this->errors($this->updateRecord($record, ['name' => 'TAKEN']));
        $this->assertSame(['This value is already in use.'], $error['fields']['name']);
    }

    public function test_describe_exposes_creation_and_partial_update_schemas(): void
    {
        ValidationResource::$makeFields = fn () => [
            Text::make('Name')->rules('required', 'max:80')->help('A short name')->placeholder('Example'),
            Text::make('Custom')->creationRules('required')->default('Draft'),
            Number::make('Tenant', 'tenant_id')->rules('numeric', 'between:1,10'),
        ];
        $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1]);
        $schema = $this->payload($this->callTool($this->token()['plain_text_token'], 'describe', ['resource' => 'records', 'id' => (string) $record->id]));
        $this->assertSame(['name'], $schema['create_schema']['required']);
        $this->assertSame([], $schema['update_schema']['required']);
        $this->assertSame(80, $schema['create_fields']['name']['maxLength']);
        $this->assertSame(['string'], $schema['create_fields']['name']['type']);
        $this->assertSame('Draft', $schema['create_fields']['custom']['default']);
        $this->assertSame('A short name Example', $schema['create_fields']['name']['description']);
        $this->assertSame(1, $schema['create_fields']['tenant_id']['minimum']);
        $this->assertSame(10, $schema['create_fields']['tenant_id']['maximum']);
    }

    public function test_creation_uses_supported_defaults_without_overriding_explicit_input(): void
    {
        ValidationResource::$makeFields = fn () => [Text::make('Name')->rules('required')->default('Draft')];
        $token = $this->token()['plain_text_token'];
        $created = $this->payload($this->callTool($token, 'create', ['resource' => 'records']));
        $this->assertSame('Draft', Record::findOrFail($created['id'])->name);
        $this->errors($this->callTool($token, 'create', ['resource' => 'records', 'fields' => ['name' => null]]));
    }

    public function test_safe_scalar_coercion_and_select_keys_work_through_nova(): void
    {
        ValidationResource::$makeFields = fn () => [
            Text::make('Name'),
            Boolean::make('Custom')->values('enabled', 'disabled'),
            Number::make('Tenant', 'tenant_id')->rules('integer', 'in:1'),
        ];
        $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1, 'custom' => 'enabled']);
        $this->payload($this->updateRecord($record, ['custom' => 'false', 'tenant_id' => '1']));
        $this->assertSame('disabled', $record->fresh()->custom);
        $this->errors($this->updateRecord($record, ['custom' => 'probably']));
        ValidationResource::$makeFields = fn () => [Select::make('Custom')->options([1 => 'One', 2 => 'Two'])];
        $this->payload($this->updateRecord($record, ['custom' => '2']));
        $this->assertSame('2', $record->fresh()->custom);
        $this->errors($this->updateRecord($record, ['custom' => '02']));
    }

    public function test_stored_passwords_and_files_are_not_treated_as_new_form_inputs(): void
    {
        foreach ([Password::class => 'confirmed', File::class => 'file'] as $fieldClass => $rule) {
            ValidationResource::$makeFields = fn () => [Text::make('Name'), $fieldClass::make('Custom')->rules('nullable', $rule)];
            ValidationResource::$afterValidationCallback = function () {
                $this->assertFalse(request()->exists('custom'));
            };
            $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1, 'custom' => 'stored-value']);
            $this->payload($this->updateRecord($record, ['name' => 'Updated']));
            $this->assertSame('stored-value', $record->fresh()->custom);
            $this->assertSame('Updated', $record->fresh()->name);
        }
    }

    public function test_boolean_cross_field_rules_use_existing_boolean_values(): void
    {
        ValidationResource::$makeFields = fn () => [
            Boolean::make('Custom')->rules('required', 'boolean'),
            Boolean::make('Feature', 'category_id')->rules('boolean', 'declined_if:custom,true'),
        ];
        $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1, 'custom' => '1', 'category_id' => 0]);
        $error = $this->errors($this->updateRecord($record, ['category_id' => true]));
        $this->assertArrayHasKey('category_id', $error['fields']);
        $this->assertSame(0, $record->fresh()->category_id);
        $this->payload($this->updateRecord($record, ['custom' => false, 'category_id' => true]));
        $this->assertSame(1, $record->fresh()->category_id);
    }

    public function test_lossy_and_non_finite_numeric_strings_are_rejected(): void
    {
        ValidationResource::$makeFields = fn () => [Number::make('Custom')];
        $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1]);
        foreach (['1e999', '1e-999', '9223372036854775808', '0.123456789012345678901'] as $value) {
            $this->assertSame('validation', $this->errors($this->updateRecord($record, ['custom' => $value]))['code']);
        }
        $this->assertNull($record->fresh()->custom);
        $this->payload($this->updateRecord($record, ['custom' => '42.5']));
        $this->assertSame('42.5', $record->fresh()->custom);
    }

    public function test_fill_time_dependencies_cannot_silently_discard_an_update(): void
    {
        ValidationResource::$makeFields = fn () => [
            Text::make('Name')->dependsOn('custom', fn ($field, $request, $data) => $field->readonly($data->custom !== 'allowed')),
            Text::make('Custom'),
        ];
        $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1, 'custom' => 'allowed']);
        $error = $this->errors($this->updateRecord($record, ['name' => 'Updated']));
        $this->assertStringContainsString('additional form context', $error['fields']['_'][0]);
        $this->assertSame('ORIGINAL', $record->fresh()->name);
        $this->payload($this->updateRecord($record, ['name' => 'Updated', 'custom' => 'allowed']));
        $this->assertSame('Updated', $record->fresh()->name);
    }

    public function test_schema_hints_do_not_serialize_custom_rules_or_hidden_defaults(): void
    {
        ValidationResource::$makeFields = fn () => [
            Text::make('Name')->rules('required', 'nullable', function ($attribute, $value, $fail) {}),
            Text::make('Secret')->canSee(fn () => false)->default('private-default'),
        ];
        $schema = $this->payload($this->callTool($this->token()['plain_text_token'], 'describe', ['resource' => 'records']));
        $this->assertSame(['string'], $schema['create_fields']['name']['type']);
        $this->assertSame(['custom'], $schema['create_fields']['name']['x-nova-rules']);
        $this->assertArrayNotHasKey('secret', $schema['create_fields']);
        $this->assertStringNotContainsString('private-default', json_encode($schema));
    }

    public function test_dynamic_readonly_field_cannot_be_written_using_omitted_dependencies(): void
    {
        ValidationResource::$makeFields = fn () => [
            Text::make('Name'),
            Text::make('Custom')->dependsOn('tenant_id', function ($field, $request, $data) {
                $field->readonly($data->tenant_id == 1);
            }),
            Number::make('Tenant', 'tenant_id')->readonly(),
        ];
        $record = Record::create(['name' => 'ORIGINAL', 'tenant_id' => 1, 'custom' => 'Stored']);
        $this->errors($this->updateRecord($record, ['custom' => 'Attack']));
        $this->assertSame('Stored', $record->fresh()->custom);
    }
}

class ValidationResource extends RecordResource
{
    public static ?Closure $makeFields = null;

    public static ?Closure $afterValidationCallback = null;

    public static array $events = [];

    public static bool $failAfterUpdate = false;

    public function fields(NovaRequest $request): array
    {
        return (self::$makeFields)($request);
    }

    public static function validateForUpdate(NovaRequest $request, ?Resource $resource = null): void
    {
        if (! $resource instanceof self || ! $request->newResourceWith($resource->model()) instanceof self) {
            throw new \RuntimeException('Application hooks must receive the original resource.');
        }
        self::$events[] = 'validate';
        parent::validateForUpdate($request, $resource);
    }

    protected static function afterUpdateValidation(NovaRequest $request, Validator $validator)
    {
        self::$events[] = 'after-validation';
        if (self::$afterValidationCallback) {
            (self::$afterValidationCallback)($validator);
        }
    }

    public static function fillForUpdate(NovaRequest $request, $model): array
    {
        self::$events[] = 'fill:'.implode(',', array_keys($request->all()));

        return parent::fillForUpdate($request, $model);
    }

    public static function beforeUpdate(NovaRequest $request, Model $model): void
    {
        self::$events[] = 'before:'.implode(',', array_keys($request->all()));
    }

    public static function afterUpdate(NovaRequest $request, Model $model): void
    {
        self::$events[] = 'after';
        if (self::$failAfterUpdate) {
            throw new \RuntimeException('Private failure details');
        }
    }
}
