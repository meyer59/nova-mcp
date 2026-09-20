<?php

namespace NovaMcp\Nova;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Nova\Contracts\RelatableField;
use Laravel\Nova\Fields\BelongsTo;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\FieldCollection;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\HasOne;
use Laravel\Nova\Http\Controllers\ActionController;
use Laravel\Nova\Http\Controllers\ResourceDestroyController;
use Laravel\Nova\Http\Controllers\ResourceRestoreController;
use Laravel\Nova\Http\Controllers\ResourceStoreController;
use Laravel\Nova\Http\Controllers\ResourceUpdateController;
use Laravel\Nova\Http\Requests\CreateResourceRequest;
use Laravel\Nova\Http\Requests\LensRequest;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Http\Requests\ResourceIndexRequest;
use Laravel\Nova\Resource;
use NovaMcp\Fields\FieldRegistry;
use NovaMcp\Fields\ValidationSchema;
use NovaMcp\Nova\Requests\ActionRequest;
use NovaMcp\Nova\Requests\DeleteRequest;
use NovaMcp\Nova\Requests\DetailRequest;
use NovaMcp\Nova\Requests\RestoreRequest;
use NovaMcp\Nova\Requests\UpdateRequest;

/** Nova 5 compatibility boundary. No application models are mass-assigned. */
class Gateway
{
    public function __construct(private RequestContext $context, private ResourceRegistry $resources, private FieldRegistry $fields) {}

    public function execute(string $operation, array $arguments): array
    {
        $rules = [
            'resource' => ['required', 'string', 'max:160', 'regex:/^[a-zA-Z0-9_-]+$/D'],
            'id' => ['sometimes', 'required', 'string', 'max:160'],
            'fields' => ['sometimes', 'array', 'max:100'],
            'ids' => ['sometimes', 'array', 'min:1', 'max:'.config('nova-mcp.max_action_size')],
            'ids.*' => ['required', 'string', 'distinct', 'max:160'],
            'action' => ['sometimes', 'required', 'string', 'max:160'],
            'search' => ['sometimes', 'string', 'max:500'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:10000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.config('nova-mcp.max_page_size')],
            'filters' => ['sometimes', 'array', 'max:30'],
            'lens' => ['sometimes', 'string', 'max:160'],
            'relationship' => ['sometimes', 'string', 'max:160'],
            'mode' => ['sometimes', 'in:related,candidates'],
        ];
        $allowed = match ($operation) {
            'resources' => [], 'describe' => ['resource', 'id'], 'get', 'delete', 'restore' => ['resource', 'id'],
            'list' => ['resource', 'search', 'page', 'per_page', 'filters', 'lens'],
            'create' => ['resource', 'fields'], 'update' => ['resource', 'id', 'fields'],
            'actions' => ['resource', 'ids'], 'run_action' => ['resource', 'action', 'ids', 'fields'],
            'relationships' => ['resource', 'id', 'relationship', 'mode', 'page', 'per_page'],
            default => abort(404),
        };
        if (array_diff(array_keys($arguments), $allowed)) {
            throw ValidationException::withMessages(['arguments' => 'Unknown arguments.']);
        }
        $selected = array_intersect_key($rules, array_flip($allowed));
        if (in_array('ids', $allowed, true)) {
            $selected['ids.*'] = $rules['ids.*'];
        }
        if (in_array($operation, ['get', 'update', 'delete', 'restore', 'relationships'], true)) {
            $selected['id'][0] = 'required';
        }
        if ($operation === 'run_action') {
            $selected['action'][0] = 'required';
        }
        $a = Validator::make($arguments, $selected)->validate();
        $type = match ($operation) {
            'create' => CreateResourceRequest::class, 'update' => UpdateRequest::class,
            'delete' => DeleteRequest::class, 'restore' => RestoreRequest::class,
            'get', 'describe', 'relationships' => DetailRequest::class,
            'actions', 'run_action' => ActionRequest::class,
            default => ResourceIndexRequest::class,
        };
        $params = ['resources' => $a['ids'] ?? (isset($a['id']) ? [$a['id']] : []), 'search' => $a['search'] ?? '', 'page' => $a['page'] ?? 1];
        if (isset($a['action'])) {
            $params['action'] = $a['action'];
        }
        $request = $this->context->make($type, $a['resource'] ?? '', $params, $a['id'] ?? null,
            match ($operation) {
                'create', 'run_action' => 'POST', 'update', 'restore' => 'PUT', 'delete' => 'DELETE', default => 'GET'
            });
        // Nova selects an action from the query bag, even on POST requests.
        if (isset($a['action'])) {
            $request->query->set('action', $a['action']);
        }

        return $this->context->run($request, function () use ($operation, $a, $request) {
            if ($operation === 'resources') {
                return ['resources' => array_map(function ($class) {
                    $resourceRequest = $this->context->make(ResourceIndexRequest::class, $class::uriKey());

                    return $this->context->run($resourceRequest, fn () => $this->resources->metadata($resourceRequest, $class));
                }, $this->resources->all($request))];
            }
            $class = $this->resources->resolve($request, $a['resource']);

            return match ($operation) {
                'describe' => $this->describe($request, $class, $a),
                'list' => $this->listing($request, $class, $a),
                'get' => $this->read($request, $class),
                'create', 'update', 'delete', 'restore' => $this->mutate($operation, $request, $class, $a),
                'actions', 'run_action' => $this->actions($operation, $request, $class, $a),
                'relationships' => $this->relationships($request, $class, $a),
            };
        });
    }

    private function scopedResource(NovaRequest $request, string $class, string $id): \Laravel\Nova\Resource
    {
        $query = $class::indexQuery($request, $class::newModel()->newQuery());
        if ($request instanceof RestoreRequest) {
            $query->onlyTrashed();
        }
        $query = $class::detailQuery($request, $query);
        $model = $query->whereKey($id)->firstOrFail();
        $resource = new $class($model);
        abort_unless($resource->authorizedToView($request), 404, 'Resource unavailable.');

        return $resource;
    }

    private function read(NovaRequest $request, string $class): array
    {
        $resource = $this->scopedResource($request, $class, $request->route('resourceId'));

        return ['id' => (string) $resource->model()->getKey(), 'fields' => (object) $this->fields->values($resource->detailFields($request), $request)];
    }

    private function describe(NovaRequest $request, string $class, array $a): array
    {
        $resource = isset($a['id']) ? $this->scopedResource($request, $class, $a['id']) : new $class($class::newModel());
        $result = $this->resources->metadata($request, $class);
        $result['fields'] = (object) $this->fields->describe($resource->detailFields($request), $request);
        $token = $request->attributes->get('nova-mcp.token');
        if ($token->allows('create') && $class::authorizedToCreate($request)) {
            $create = $this->context->make(CreateResourceRequest::class, $a['resource']);
            $result['create_fields'] = (object) $this->context->run($create, function () use ($class, $create, &$result) {
                $resource = new $class($class::newModel());
                $fields = $resource->creationFields($create)->applyDependsOn($create)->onlyCreateFields($create, $resource->model());

                $result['create_blockers'] = $this->fields->blockers($fields->withoutReadonly($create)->withoutUnfillable(), $create);

                return $this->fields->describe($fields, $create, true);
            });
            $result['create_schema'] = app(ValidationSchema::class)->object((array) $result['create_fields'], false);
        }
        if (isset($a['id']) && $token->allows('update') && $resource->authorizedToUpdate($request)) {
            $update = $this->context->make(UpdateRequest::class, $a['resource'], [], $a['id']);
            $result['update_fields'] = (object) $this->context->run($update, function () use ($resource, $update, &$result) {
                $state = app(UpdateState::class);
                $update->replace($state->values($resource, $update));
                $update->replace($state->forFields($resource, $update));

                $fields = $resource->updateFields($update)->applyDependsOn($update)->onlyUpdateFields($update, $resource->model());
                $result['update_blockers'] = $this->fields->blockers($fields->withoutReadonly($update)->withoutUnfillable(), $update, $update->all());

                return $this->fields->describe($fields, $update, true);
            });
            $result['update_schema'] = app(ValidationSchema::class)->object((array) $result['update_fields'], true);
        }
        $result['filters'] = $resource->availableFilters($request)->map(fn ($filter) => ['key' => $filter->key(), 'name' => $filter->name(), 'options' => $filter->options($request)])->values()->all();
        $result['lenses'] = $resource->availableLenses($request)->map(fn ($lens) => ['key' => $lens->uriKey(), 'name' => $lens->name()])->values()->all();
        if ($token->allows('relationships')) {
            $result['relationships'] = array_values(array_map(fn ($field) => ['field' => $field->attribute, 'resource' => $field->resourceName, 'writable' => $field instanceof BelongsTo], $this->relationFields($resource, $request)));
        }

        return $result;
    }

    private function listing(ResourceIndexRequest $request, string $class, array $a): array
    {
        $resource = new $class($class::newModel());
        $filters = $resource->availableFilters($request)->keyBy(fn ($filter) => $filter->key());
        $encoded = [];
        foreach ($a['filters'] ?? [] as $key => $value) {
            abort_unless($filters->has($key), 422, 'Filter unavailable.');
            $encoded[] = [$key => $value];
        }
        $request->merge(['filters' => base64_encode(json_encode($encoded, JSON_THROW_ON_ERROR))]);
        $query = $request->toQuery();
        $lens = null;
        $fieldRequest = $request;
        if (isset($a['lens'])) {
            $lensRequest = $this->context->make(LensRequest::class, $a['resource'], ['lens' => $a['lens'], 'search' => $a['search'] ?? '', 'filters' => $request->filters]);
            $lens = $this->context->run($lensRequest, fn () => $resource->availableLenses($lensRequest)->first(fn ($l) => $l->uriKey() === $a['lens']));
            abort_unless($lens, 404, 'Lens unavailable.');
            $query = $this->context->run($lensRequest, fn () => $lens::query($lensRequest, $query));
            // A lens may reshape queries. Re-apply the resource's scoped key set.
            $model = $class::newModel();
            abort_unless($query instanceof Builder && $query->getModel()::class === $model::class, 422, 'Unsupported lens query.');
            $base = $query->getQuery();
            abort_if($base->from !== $model->getTable() || $base->unions || $base->groups || $base->havings || $base->joins || $base->aggregate, 422, 'Unsupported lens query.');
            foreach ($base->columns ?? ['*'] as $column) {
                // Expressions/aliases can map another row's data onto an authorized ID.
                abort_unless(is_string($column) && preg_match('/^(?:[a-zA-Z_][a-zA-Z0-9_]*\.)?(?:[a-zA-Z_][a-zA-Z0-9_]*|\*)$/D', $column), 422, 'Unsupported lens projection.');
            }
            $scope = $class::indexQuery($request, $model->newQuery())->select($model->getQualifiedKeyName());
            // Eloquent groups existing OR predicates when applying a global scope.
            // A plain appended whereIn could be bypassed by the lens's OR clauses.
            $query->withGlobalScope('nova-mcp.visibility', fn ($builder) => $builder->whereIn($model->getQualifiedKeyName(), $scope));
            $fieldRequest = $lensRequest;
        }
        $size = $a['per_page'] ?? min(25, config('nova-mcp.max_page_size'));
        $page = $query->simplePaginate($size, ['*'], 'page', $a['page'] ?? 1);
        $rows = [];
        foreach ($page->items() as $model) {
            $row = $this->context->run($fieldRequest, function () use ($model, $class, $fieldRequest, $lens) {
                $item = new $class($model);
                // Aggregate lenses lacking model IDs cannot be safely authorized.
                if ($model->getKey() === null || ! $item->authorizedToView($fieldRequest)) {
                    return null;
                }
                $fields = $lens ? (clone $lens)->setResource($model)->resolveFields($fieldRequest) : $item->indexFields($fieldRequest);

                return ['id' => (string) $model->getKey(), 'fields' => (object) $this->fields->values($fields, $fieldRequest)];
            });
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return ['resources' => $rows, 'page' => $page->currentPage(), 'has_more' => $page->hasMorePages()];
    }

    private function mutate(string $operation, NovaRequest $request, string $class, array $a): array
    {
        $resource = $operation === 'create' ? new $class($class::newModel()) : $this->scopedResource($request, $class, $a['id']);
        $authorization = 'authorizedTo'.ucfirst($operation);
        abort_unless($resource->{$authorization}($request), 403);
        if (in_array($operation, ['create', 'update'], true)) {
            $input = $a['fields'] ?? [];
            $request->merge($input);
            if ($operation === 'update') {
                $state = app(UpdateState::class);
                $request->replace($input + $state->values($resource, $request));
                $request->replace($input + $state->forFields($resource, $request));
            }
            $fields = $operation === 'create' ? $resource->creationFields($request) : $resource->updateFields($request);
            $fields = $fields->applyDependsOn($request);
            $fields = $operation === 'create' ? $fields->onlyCreateFields($request, $resource->model()) : $fields->onlyUpdateFields($request, $resource->model());
            $prepared = $this->fields->prepare($fields, $input, $request);
            $request->replace($prepared);
        }
        $controller = match ($operation) {
            'create' => ResourceStoreController::class, 'update' => ResourceUpdateController::class,
            'delete' => ResourceDestroyController::class, 'restore' => ResourceRestoreController::class,
        };
        if ($request instanceof UpdateRequest) {
            $request->bridgeValidation = true;
        }
        try {
            $response = app($controller)($request);
        } finally {
            if ($request instanceof UpdateRequest) {
                $request->bridgeValidation = false;
            }
        }
        $payload = $response instanceof JsonResponse ? $response->getData(true) : [];

        return ['status' => 'completed', 'id' => (string) ($payload['id'] ?? $a['id'] ?? '')];
    }

    private function actions(string $operation, ActionRequest $request, string $class, array $a): array
    {
        $models = [];
        foreach ($a['ids'] ?? [] as $id) {
            $models[] = $this->scopedResource($request, $class, $id);
        }
        $resource = count($models) === 1 ? $models[0] : new $class($class::newModel());
        $actions = $resource->resolveActions($request)->filter(function ($action) use ($models, $request) {
            if (! $action->authorizedToSee($request) || ($models === [] && ! $action->isStandalone())) {
                return false;
            }
            foreach ($models as $model) {
                if (! $action->authorizedToRun($request, $model->model()) || ! $model->authorizedToRunAction($request, $action)) {
                    return false;
                }
            }

            return ! $action->sole || count($models) === 1;
        });
        if ($operation === 'actions') {
            return ['actions' => $actions->map(function ($action) use ($request) {
                $fields = $this->fields->describe(FieldCollection::make($action->fields($request))->authorized($request)->applyDependsOn($request), $request, true);

                return [
                    'key' => $action->uriKey(), 'name' => $action->name(),
                    'fields' => (object) $fields,
                    'schema' => app(ValidationSchema::class)->object($fields, false),
                ];
            })->values()->all()];
        }
        $action = $actions->first(fn ($action) => $action->uriKey() === $a['action']);
        abort_unless($action, 404, 'Action unavailable.');
        $input = $a['fields'] ?? [];
        $request->merge($input);
        $fields = FieldCollection::make($action->fields($request))->authorized($request)->applyDependsOn($request);
        $prepared = $this->fields->prepare($fields, $input, $request);
        $request->replace($prepared + ['resources' => $a['ids'] ?? []]);
        $request->query->set('action', $a['action']);
        // Nova handles validation, fillForAction, batching, queues, and action events.
        $response = app(ActionController::class)->store($request);
        if ($response instanceof JsonResponse) {
            $response = $response->getData(true);
        }

        return ['result' => $response];
    }

    private function relationFields(\Laravel\Nova\Resource $resource, NovaRequest $request): array
    {
        $exposed = $this->resources->all($request);

        return $resource->detailFields($request)->filter(fn ($field) => $field instanceof RelatableField
            && isset($field->resourceClass) && in_array($field->resourceClass, $exposed, true)
            && in_array($field::class, [BelongsTo::class, HasMany::class, HasOne::class, BelongsToMany::class], true)
        )->all();
    }

    private function relationships(NovaRequest $request, string $class, array $a): array
    {
        abort_unless($request->attributes->get('nova-mcp.token')->allows('read'), 403);
        $parent = $this->scopedResource($request, $class, $a['id']);
        $fields = $this->relationFields($parent, $request);
        if (! isset($a['relationship'])) {
            return ['relationships' => array_values(array_map(fn ($field) => ['field' => $field->attribute, 'resource' => $field->resourceName], $fields))];
        }
        $field = collect($fields)->first(fn ($field) => $field->attribute === $a['relationship']);
        abort_unless($field, 404, 'Relationship unavailable.');
        $relatedClass = $field->resourceClass;
        $relatedRequest = $this->context->make(ResourceIndexRequest::class, $relatedClass::uriKey());
        if (($a['mode'] ?? 'related') === 'candidates') {
            abort_unless($field::class === BelongsTo::class && $parent->authorizedToUpdate($request), 403);
            $query = $field->buildAssociatableQuery($request, $relatedClass, false)->toBase();
        } else {
            $query = $parent->model()->{$field->relationshipName()}()->getQuery();
        }

        return $this->context->run($relatedRequest, function () use ($query, $relatedClass, $relatedRequest, $a) {
            abort_unless($relatedClass::authorizedToViewAny($relatedRequest), 404);
            // Application relationships and relatable callbacks may remove global scopes.
            // Intersect their IDs with a fresh, scoped resource query before reading data.
            $model = $relatedClass::newModel();
            $key = $model->getQualifiedKeyName();
            $scoped = $relatedClass::indexQuery($relatedRequest, $model->newQuery());
            $scoped->whereIn($key, $query->select($key));
            $page = $scoped->simplePaginate($a['per_page'] ?? min(25, config('nova-mcp.max_page_size')), ['*'], 'page', $a['page'] ?? 1);
            $rows = [];
            foreach ($page->items() as $model) {
                $resource = new $relatedClass($model);
                if ($resource->authorizedToView($relatedRequest)) {
                    $rows[] = ['id' => (string) $model->getKey(), 'fields' => (object) $this->fields->values($resource->indexFields($relatedRequest), $relatedRequest)];
                }
            }

            return ['resources' => $rows, 'page' => $page->currentPage(), 'has_more' => $page->hasMorePages()];
        });
    }
}
