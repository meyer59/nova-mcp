<?php

namespace NovaMcp\Tests\Feature;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Actions\CallQueuedAction;
use Laravel\Nova\Actions\DestructiveAction;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use NovaMcp\Fields\ValidationSchema;
use NovaMcp\Http\Middleware\Authenticate;
use NovaMcp\Models\Token;
use NovaMcp\NovaMcp;
use NovaMcp\Tests\Fixtures\Record;
use NovaMcp\Tests\Fixtures\RecordPolicy;
use NovaMcp\Tests\Fixtures\RecordResource;
use NovaMcp\Tests\TestCase;

class ActionSafetyTest extends TestCase
{
    private string $bearer;

    private Record $record;

    protected function setUp(): void
    {
        parent::setUp();
        SafetyResource::$factory = fn () => [new SafetyAction, new SensitiveAction, new SensitiveChildAction];
        SafetyResource::$contexts = [];
        SafetyAction::$response = Action::message('Done');
        SafetyAction::$executed = 0;
        SensitiveAction::$seen = 0;
        SensitiveAction::$ran = 0;
        Nova::$resources = [SafetyResource::class];
        $this->bearer = $this->token()['plain_text_token'];
        $this->record = Record::create(['name' => 'Record', 'tenant_id' => 1]);
    }

    private function listing(?array $ids = null): array
    {
        return $this->payload($this->callTool($this->bearer, 'actions', ['resource' => 'records'] + ($ids === null ? [] : ['ids' => $ids])));
    }

    private function runAction(string $action = 'safety-action', ?array $ids = null, array $fields = [])
    {
        return $this->callTool($this->bearer, 'run_action', [
            'resource' => 'records', 'action' => $action, 'fields' => $fields,
        ] + ($ids === null ? [] : ['ids' => $ids]));
    }

    private function ids(): array
    {
        return [(string) $this->record->id];
    }

    public function test_helpers_work_in_outer_and_inner_callbacks_and_are_cleared(): void
    {
        $outerSeen = false;
        Gate::define('accessNovaMcp', function ($user, $request) use (&$outerSeen) {
            $outerSeen = NovaMcp::isMcpRequest($request) && NovaMcp::token($request) instanceof Token;

            return true;
        });
        $this->payload($this->callTool($this->bearer, 'describe', ['resource' => 'records']));
        $this->listing($this->ids());
        $this->payload($this->runAction(ids: $this->ids()));
        $this->assertTrue($outerSeen);
        foreach (['resource', 'field', 'action', 'run'] as $context) {
            $this->assertContains($context, array_keys(SafetyResource::$contexts));
            $this->assertNotContains(false, SafetyResource::$contexts[$context]);
        }
        $this->assertFalse(NovaMcp::isMcpRequest());
        $this->assertNull(NovaMcp::token());
        $request = Request::create('/nova');
        foreach (['forged', ['id' => 1], new \stdClass, null] as $fake) {
            $request->attributes->set('nova-mcp.token', $fake);
            $this->assertFalse(NovaMcp::isMcpRequest($request));
            $this->assertNull(NovaMcp::token($request));
        }
    }

    public function test_helpers_do_not_persist_on_reused_requests_even_after_exceptions(): void
    {
        $request = Request::create('/mcp/nova', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$this->bearer]);
        try {
            app(Authenticate::class)->handle($request, function ($request) {
                $this->assertTrue(NovaMcp::isMcpRequest($request));
                throw new \RuntimeException('intentional');
            });
            $this->fail('Expected exception');
        } catch (\RuntimeException $exception) {
            $this->assertSame('intentional', $exception->getMessage());
        }
        $this->assertNull(NovaMcp::token($request));
        $request->headers->remove('Authorization');
        $response = app(Authenticate::class)->handle($request, fn () => $this->fail('Must not authenticate'));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse(NovaMcp::isMcpRequest($request));
    }

    public function test_excluded_actions_and_subclasses_are_hidden_without_callbacks_or_side_effects(): void
    {
        config(['nova-mcp.excluded_actions' => [SensitiveAction::class]]);
        $this->assertSame(['safety-action'], array_column($this->listing($this->ids())['actions'], 'key'));
        $unknown = $this->runAction('unknown', $this->ids())->json('result');
        foreach (['sensitive-action', 'sensitive-child-action'] as $key) {
            $response = $this->runAction($key, $this->ids());
            $this->assertSame($unknown, $response->json('result'));
            $this->assertSame('unavailable', json_decode($response->json('result.content.0.text'), true)['code']);
        }
        // The controller must not call excluded callbacks when running another action.
        $this->payload($this->runAction(ids: $this->ids()));
        $this->assertSame(0, SensitiveAction::$seen);
        $this->assertSame(0, SensitiveAction::$ran);
        $this->assertSame(1, SafetyAction::$executed);
    }

    public function test_inclusions_inherit_and_exclusions_win_and_bad_config_fails_closed(): void
    {
        config(['nova-mcp.included_actions' => [SensitiveAction::class]]);
        $this->assertSame(['sensitive-action', 'sensitive-child-action'], array_column($this->listing($this->ids())['actions'], 'key'));
        config(['nova-mcp.excluded_actions' => [SensitiveAction::class]]);
        $this->assertSame([], $this->listing($this->ids())['actions']);
        foreach (['included_actions', 'excluded_actions'] as $key) {
            foreach ([null, '*', false, [new \stdClass]] as $value) {
                config(['nova-mcp.included_actions' => [], 'nova-mcp.excluded_actions' => [], 'nova-mcp.'.$key => $value]);
                $this->assertSame([], $this->listing($this->ids())['actions']);
                $this->runAction(ids: $this->ids())->assertJsonPath('result.isError', true);
            }
        }
        $this->assertSame(0, SafetyAction::$executed);
    }

    public function test_standalone_actions_obey_the_same_exposure_controls(): void
    {
        SafetyResource::$factory = fn () => [(new SafetyAction)->standalone(), (new SensitiveAction)->standalone()];
        config(['nova-mcp.excluded_actions' => [SensitiveAction::class]]);
        $this->assertSame(['safety-action'], array_column($this->listing()['actions'], 'key'));
        $this->runAction('sensitive-action')->assertJsonPath('result.isError', true);
        $this->assertSame('completed', $this->payload($this->runAction())['result']['status']);
        $this->assertSame(0, SensitiveAction::$seen);
    }

    public function test_status_results_never_return_navigation_modal_event_or_download_payloads(): void
    {
        $secret = 'https://example.invalid/login?bearer=PRIVATE-BEARER';
        foreach ([
            [Action::openInNewTab($secret), 'redirect'],
            [Action::redirect($secret), 'redirect'],
            [Action::visit('/private', ['query' => ['token' => $secret]]), 'visit'],
            [Action::download($secret, 'PRIVATE-BEARER.csv'), 'download'],
            [Action::modal('secret-modal', ['token' => $secret]), 'modal'],
            [['event' => ['name' => 'secret-event', 'data' => $secret], 'deleted' => true], 'none'],
            [response()->json(['redirect' => $secret, 'extra' => $secret]), 'redirect'],
        ] as [$response, $type]) {
            SafetyAction::$response = $response;
            $http = $this->runAction(ids: $this->ids());
            $result = $this->payload($http)['result'];
            $this->assertSame(['status' => 'completed', 'message' => null, 'type' => $type], $result);
            $this->assertStringNotContainsString('PRIVATE-BEARER', $http->getContent());
            $this->assertStringNotContainsString('example.invalid', $http->getContent());
            $this->assertStringNotContainsString('secret-modal', $http->getContent());
        }
    }

    public function test_messages_are_bounded_and_danger_is_an_mcp_error(): void
    {
        SafetyAction::$response = Action::message("<b>OK</b>\n\x00".str_repeat('é', 1200));
        $result = $this->payload($this->runAction(ids: $this->ids()))['result'];
        $this->assertSame('completed', $result['status']);
        $this->assertSame(1000, mb_strlen($result['message']));
        $this->assertStringStartsWith('OKé', $result['message']);
        SafetyAction::$response = ActionResponse::danger("<b>Failed</b>\n")->withRedirect('https://example.invalid/PRIVATE-BEARER');
        $response = $this->runAction(ids: $this->ids());
        $response->assertJsonPath('result.isError', true);
        $this->assertSame(['code' => 'action_failed', 'status' => 'failed', 'message' => 'Failed', 'type' => 'danger'], json_decode($response->json('result.content.0.text'), true));
        $this->assertStringNotContainsString('PRIVATE-BEARER', $response->getContent());
    }

    public function test_full_results_require_explicit_opt_in_and_do_not_bypass_exposure(): void
    {
        SafetyAction::$response = Action::openInNewTab('https://example.invalid/PRIVATE-BEARER');
        config(['nova-mcp.full_result_actions' => [SafetyAction::class]]);
        $this->assertStringContainsString('PRIVATE-BEARER', $this->runAction(ids: $this->ids())->getContent());
        config(['nova-mcp.full_result_actions' => [], 'nova-mcp.action_results' => 'full']);
        $this->assertStringContainsString('PRIVATE-BEARER', $this->runAction(ids: $this->ids())->getContent());
        config(['nova-mcp.excluded_actions' => [SafetyAction::class]]);
        $this->runAction(ids: $this->ids())->assertJsonPath('result.isError', true);
        config(['nova-mcp.excluded_actions' => [], 'nova-mcp.action_results' => 'invalid', 'nova-mcp.full_result_actions' => '*']);
        $this->assertStringNotContainsString('PRIVATE-BEARER', $this->runAction(ids: $this->ids())->getContent());
        app('config')->offsetUnset('nova-mcp.action_results');
        $this->assertSame('completed', $this->payload($this->runAction(ids: $this->ids()))['result']['status']);
    }

    public function test_metadata_and_queued_status_reflect_nova_actions(): void
    {
        Queue::fake();
        SafetyResource::$factory = fn () => [
            (new SafetyAction)->sole()->confirmText('<b>'.str_repeat('é', 600)."</b>\n"),
            new QueuedSafetyAction,
            new DestructiveSafetyAction,
            (new SensitiveAction)->standalone(),
        ];
        $actions = collect($this->listing($this->ids())['actions'])->keyBy('key');
        $this->assertTrue($actions['safety-action']['sole']);
        $this->assertFalse($actions['safety-action']['queued']);
        $this->assertFalse($actions['safety-action']['destructive']);
        $this->assertSame(str_repeat('é', 500), $actions['safety-action']['confirm_text']);
        $this->assertTrue($actions['queued-safety-action']['queued']);
        $this->assertTrue($actions['destructive-safety-action']['destructive']);
        $this->assertTrue($actions['sensitive-action']['standalone']);
        $this->assertSame(['status' => 'queued'], $this->payload($this->runAction('queued-safety-action', $this->ids()))['result']);
        Queue::assertPushed(CallQueuedAction::class);
        $this->assertSame(0, SafetyAction::$executed);
    }

    public function test_audits_contain_action_key_and_count_without_input_or_target_ids(): void
    {
        config(['nova-mcp.audit_channel' => 'action-audit']);
        Log::spy();
        Log::shouldReceive('channel')->with('action-audit')->andReturnSelf();
        $second = Record::create(['name' => 'Second', 'tenant_id' => 1]);
        $this->payload($this->runAction(ids: [(string) $this->record->id, (string) $second->id], fields: ['note' => 'PRIVATE-FIELD']));
        Log::shouldHaveReceived('info')->with('nova-mcp.mutation.started', \Mockery::on(fn ($meta) => $meta['action'] === 'safety-action' && $meta['targets'] === 2
            && array_keys($meta) === ['token_id', 'tool', 'action', 'targets']
        ))->once();
        config(['nova-mcp.excluded_actions' => [SensitiveAction::class]]);
        $this->runAction('sensitive-action', $this->ids())->assertJsonPath('result.isError', true);
        Log::shouldHaveReceived('debug')->with('nova-mcp.action.hidden', \Mockery::on(fn ($meta) => $meta['action'] === 'sensitive-action' && array_keys($meta) === ['token_id', 'tool', 'action']
        ))->once();
    }

    public function test_audit_can_be_disabled_and_nova_denials_still_win(): void
    {
        config(['nova-mcp.audit' => false, 'nova-mcp.action_results' => 'full']);
        Log::spy();
        config(['nova-mcp.excluded_actions' => [SensitiveAction::class]]);
        $this->runAction('sensitive-action', $this->ids())->assertJsonPath('result.isError', true);
        SafetyResource::$factory = fn () => [(new SafetyAction)->canRun(fn () => false)];
        $this->assertSame([], $this->listing($this->ids())['actions']);
        $this->runAction(ids: $this->ids())->assertJsonPath('result.isError', true);
        Log::shouldNotHaveReceived('channel');
        $this->assertSame(0, SafetyAction::$executed);
    }

    public function test_full_result_exceptions_are_exact_and_use_the_executed_action_class(): void
    {
        SafetyAction::$response = Action::redirect('https://example.invalid/PRIVATE-BEARER');
        config(['nova-mcp.full_result_actions' => [SafetyAction::class]]);
        $this->assertStringNotContainsString('PRIVATE-BEARER', $this->runAction('sensitive-child-action', $this->ids())->getContent());

        // A resource can resolve different actions with the same URI as its request
        // changes. Result opt-in must belong to the action Nova actually executed.
        SafetyResource::$factory = fn () => [request()->exists('note') ? new SameKeySensitiveAction : new SafetyAction];
        $response = $this->runAction(ids: $this->ids(), fields: ['note' => 'select second action']);
        $this->assertSame('completed', $this->payload($response)['result']['status']);
        $this->assertStringNotContainsString('PRIVATE-BEARER', $response->getContent());

        config(['nova-mcp.excluded_actions' => [SameKeySensitiveAction::class]]);
        SensitiveAction::$seen = 0;
        $before = SafetyAction::$executed;
        $this->runAction(ids: $this->ids(), fields: ['note' => 'select excluded action'])->assertJsonPath('result.isError', true);
        $this->assertSame(0, SensitiveAction::$seen);
        $this->assertSame($before, SafetyAction::$executed);
    }

    public function test_policy_helpers_remove_access_without_affecting_browser_requests(): void
    {
        Gate::policy(Record::class, McpActionPolicy::class);
        $this->assertSame([], $this->listing($this->ids())['actions']);
        $this->runAction(ids: $this->ids())->assertJsonPath('result.isError', true);
        $browser = Request::create('/nova');
        $action = (new SafetyAction)->canSee(fn ($request) => ! NovaMcp::isMcpRequest($request));
        $this->assertTrue($action->authorizedToSee($browser));
        $this->assertFalse(NovaMcp::isMcpRequest($browser));
    }

    public function test_failure_audit_and_status_do_not_claim_completion(): void
    {
        Log::spy();
        Log::shouldReceive('channel')->andReturnSelf();
        SafetyAction::$response = Action::danger('Failed');
        $this->runAction(ids: $this->ids())->assertJsonPath('result.isError', true);
        Log::shouldHaveReceived('info')->with('nova-mcp.mutation.finished', \Mockery::on(fn ($meta) => $meta['outcome'] === 'failed' && $meta['action'] === 'safety-action' && $meta['targets'] === 1
        ))->once();
        Log::shouldNotHaveReceived('info', ['nova-mcp.mutation.completed', \Mockery::any()]);
    }

    public function test_conditional_rule_hints_include_acceptance_and_decline_conditions(): void
    {
        $request = NovaRequest::create('/');
        foreach (['declined_if:other,true', 'accepted_if:other,true', 'required_unless:other,false', 'required_with_all:other,another'] as $rule) {
            $schema = app(ValidationSchema::class)->enrich(Text::make('Note')->rules($rule), $request, ['type' => ['string', 'null']]);
            $this->assertSame(['conditional'], $schema['x-nova-rules']);
        }
    }
}

class SafetyResource extends RecordResource
{
    public static $factory;

    public static array $contexts = [];

    public static function capture(string $context, $request): bool
    {
        self::$contexts[$context][] = NovaMcp::isMcpRequest($request) && NovaMcp::isMcpRequest()
            && NovaMcp::token($request) === NovaMcp::token();

        return true;
    }

    public function fields(NovaRequest $request): array
    {
        self::capture('resource', $request);

        return [Text::make('Name')->canSee(fn ($request) => self::capture('field', $request))];
    }

    public function actions(NovaRequest $request): array
    {
        return (self::$factory)();
    }
}

class SafetyAction extends Action
{
    public static mixed $response = null;

    public static int $executed = 0;

    public function fields(NovaRequest $request): array
    {
        return [Text::make('Note')];
    }

    public function authorizedToSee(Request $request)
    {
        SafetyResource::capture('action', $request);

        return parent::authorizedToSee($request);
    }

    public function authorizedToRun(Request $request, $model)
    {
        SafetyResource::capture('run', $request);

        return parent::authorizedToRun($request, $model);
    }

    public function handle(ActionFields $fields, Collection $models)
    {
        self::$executed++;

        return self::$response;
    }
}

class SensitiveAction extends SafetyAction
{
    public static int $seen = 0;

    public static int $ran = 0;

    public function authorizedToSee(Request $request)
    {
        self::$seen++;

        return parent::authorizedToSee($request);
    }

    public function authorizedToRun(Request $request, $model)
    {
        self::$ran++;

        return parent::authorizedToRun($request, $model);
    }
}

class SensitiveChildAction extends SensitiveAction {}

class SameKeySensitiveAction extends SensitiveAction
{
    public function uriKey()
    {
        return 'safety-action';
    }
}

class McpActionPolicy extends RecordPolicy
{
    public function runAction($user, $record, $action): bool
    {
        return ! NovaMcp::isMcpRequest() && parent::update($user, $record);
    }
}

class QueuedSafetyAction extends SafetyAction implements ShouldQueue {}

class DestructiveSafetyAction extends DestructiveAction
{
    public function handle(ActionFields $fields, Collection $models)
    {
        return static::message('Done');
    }
}
