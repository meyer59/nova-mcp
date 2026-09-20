<?php

namespace NovaMcp\Mcp;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use NovaMcp\Nova\Gateway;
use NovaMcp\Support\Audit;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class NovaTool extends Tool
{
    public function __construct(private string $operation)
    {
        $this->name = 'nova.'.$operation;
    }

    public function ability(): string
    {
        return match ($this->operation) {
            'resources', 'describe', 'list', 'get' => 'read',
            'run_action', 'actions' => 'actions',
            default => $this->operation,
        };
    }

    public function shouldRegister(): bool
    {
        return request()->attributes->get('nova-mcp.token')?->allows($this->ability()) ?? false;
    }

    public function toArray(): array
    {
        $keys = match ($this->operation) {
            'resources' => [], 'describe' => ['resource', 'id'],
            'list' => ['resource', 'search', 'page', 'per_page', 'filters', 'lens'],
            'get', 'delete', 'restore' => ['resource', 'id'],
            'create' => ['resource', 'fields'], 'update' => ['resource', 'id', 'fields'],
            'actions' => ['resource', 'ids'], 'run_action' => ['resource', 'action', 'ids', 'fields'],
            'relationships' => ['resource', 'id', 'relationship', 'mode', 'page', 'per_page'],
        };
        $properties = [];
        foreach ($keys as $key) {
            $properties[$key] = match ($key) {
                'fields', 'filters' => ['type' => 'object', 'additionalProperties' => true],
                'ids' => ['type' => 'array', 'items' => ['type' => 'string'], 'minItems' => 1, 'maxItems' => config('nova-mcp.max_action_size')],
                'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => config('nova-mcp.max_page_size')],
                'mode' => ['type' => 'string', 'enum' => ['related', 'candidates']],
                default => ['type' => 'string'],
            };
        }
        $required = $this->operation === 'resources' ? [] : ['resource'];
        if (in_array($this->operation, ['get', 'update', 'delete', 'restore', 'relationships'], true)) {
            $required[] = 'id';
        }
        if ($this->operation === 'run_action') {
            $required[] = 'action';
        }
        $read = in_array($this->operation, ['resources', 'describe', 'list', 'get', 'actions', 'relationships'], true);

        return ['name' => $this->name, 'description' => match ($this->operation) {
            'resources' => 'List exposed, authorized Nova resources and their application-provided descriptions.',
            'describe' => 'Read resource documentation and describe visible fields, filters and lenses. Supply id for record-specific update fields.',
            'list' => 'Query through Nova index scope, search and filters. Optional authorized lens. Paginated results.',
            'get' => 'Read one visible record within the Nova index and detail scopes.',
            'actions' => 'Discover runnable actions for explicit ids. Without ids, lists standalone actions only.',
            'run_action' => 'Execute a discovered Nova action. May queue jobs or cause external side effects.',
            'relationships' => 'Discover/read visible relationships. candidates supports BelongsTo relatable queries. Read access also required.',
            default => ucfirst($this->operation).' a Nova resource using Nova authorization, validation, filling and lifecycle hooks.',
        }, 'inputSchema' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required, 'additionalProperties' => false],
            'annotations' => ['readOnlyHint' => $read, 'destructiveHint' => ! $read, 'idempotentHint' => $read, 'openWorldHint' => true]];
    }

    public function handle(Request $request): Response
    {
        $token = request()->attributes->get('nova-mcp.token');
        if (! $token || ! $token->allows($this->ability())) {
            return Response::error('This token does not allow this operation.');
        }
        $outcome = 'failed';
        $mutation = in_array($this->operation, ['create', 'update', 'delete', 'restore', 'run_action'], true);
        $audit = app(Audit::class);
        $meta = ['token_id' => $token->id, 'tool' => $this->name];
        try {
            if ($mutation) {
                $audit->record('mutation.started', $meta);
            }
            $result = app(Gateway::class)->execute($this->operation, $request->all());
            if ($mutation) {
                $audit->record('mutation.completed', $meta);
            }

            $outcome = 'completed';

            return Response::json($result);
        } catch (ValidationException $e) {
            // Custom application messages can contain secrets or hidden field names.
            return Response::error('Validation failed. Check the visible field schema and supplied values.');
        } catch (AuthorizationException|ModelNotFoundException $e) {
            return Response::error('Resource or operation unavailable.');
        } catch (HttpExceptionInterface $e) {
            return Response::error($e->getStatusCode() === 422 ? 'Invalid operation or field input.' : 'Resource or operation unavailable.');
        } catch (Throwable $e) {

            $audit->record('operation.failed', $meta + ['exception' => $e::class]);

            return Response::error('The Nova operation failed. Check the server audit log.');
        } finally {
            if ($mutation) {
                $audit->record('mutation.finished', $meta + ['outcome' => $outcome]);
            }
        }
    }
}
