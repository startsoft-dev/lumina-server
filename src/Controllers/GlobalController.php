<?php

namespace Lumina\LaravelApi\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\QueryBuilder;

class GlobalController extends Controller
{
    protected $modelClass;

    /**
     * Get the model slug from the route defaults.
     * The model is set via ->defaults('model', $slug) during route registration.
     */
    protected function getModelSlug(Request $request): string
    {
        return $request->route()->defaults['model']
            ?? $request->route('model')
            ?? abort(404, 'Model not specified');
    }

    /**
     * Get the resource ID from the route parameters.
     */
    protected function getResourceId(Request $request): string
    {
        return $request->route('id')
            ?? abort(404, 'Resource ID not specified');
    }

    /**
     * Resolve and set the model class for the given model name.
     */
    protected function resolveModelClass(string $model): void
    {
        if (!isset(config('lumina.models')[$model])) {
            abort(404, "The {$model} model does not exist");
        }

        $modelClass = config('lumina.models')[$model];

        if (!class_exists($modelClass)) {
            abort(404, "The {$model} model does not exist");
        }

        $this->modelClass = app()->make($modelClass);
    }

    public function index(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        Gate::forUser(auth('sanctum')->user())->authorize('viewAny', $this->modelClass::class);

        $query = QueryBuilder::for($this->modelClass::class);

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        if (property_exists($this->modelClass, 'allowedFilters')) {
            $query = $query->allowedFilters($this->modelClass::$allowedFilters);
        } elseif ($request->has('filters')) {
            return response()->json(['message' => 'Filters are not allowed'], 403);
        }
        if (property_exists($this->modelClass, 'defaultSort')) {
            $query = $query->defaultSort($this->modelClass::$defaultSort);
        }
        if (property_exists($this->modelClass, 'allowedSorts')) {
            $query = $query->allowedSorts($this->modelClass::$allowedSorts);
        }
        if (property_exists($this->modelClass, 'allowedFields')) {
            $query = $query->allowedFields($this->modelClass::$allowedFields);
        }
        $includeAuthResponse = $this->authorizeIncludes($request);
        if ($includeAuthResponse !== null) {
            return $includeAuthResponse;
        }
        if (property_exists($this->modelClass, 'allowedIncludes')) {
            $query = $query->allowedIncludes($this->modelClass::$allowedIncludes);
        }

        $this->applySearch($query, $request);

        // Pagination: use ?per_page=N for paginated results, omit for all results
        // Models can set a default via Laravel's $perPage property or static $paginationEnabled
        $perPage = $request->input('per_page');
        $paginationEnabled = property_exists($this->modelClass, 'paginationEnabled')
            ? $this->modelClass::$paginationEnabled
            : false;

        if ($perPage !== null || $paginationEnabled) {
            $perPage = (int) ($perPage ?? $this->modelClass->getPerPage());
            $perPage = max(1, min($perPage, 100)); // clamp between 1 and 100

            $paginator = $query->paginate($perPage);

            return response()->json($paginator->items())
                ->header('X-Current-Page', $paginator->currentPage())
                ->header('X-Last-Page', $paginator->lastPage())
                ->header('X-Per-Page', $paginator->perPage())
                ->header('X-Total', $paginator->total());
        }

        return $query->get();
    }

    public function store(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        Gate::forUser(auth('sanctum')->user())->authorize('create', $this->modelClass::class);
        $validator = $this->modelClass->validateStore($request);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $validated = $validator->validated();

        // Add organization_id if multi-tenant is enabled
        $this->addOrganizationToData($validated);

        return $this->modelClass::create($validated);
    }

    public function show(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        $id = $this->getResourceId($request);
        $organization = request()->get('organization');
        $mismatch = $this->organizationIdMismatchResponse($request, $organization);
        if ($mismatch !== null) {
            return $mismatch;
        }
        $isOrganizationResource = $organization && get_class($organization) === get_class($this->modelClass);

        // For the Organization resource, scope already restricts to the current org; do not filter by route id to avoid no-result
        $query = QueryBuilder::for($this->modelClass::class);
        if (! $isOrganizationResource) {
            $query->where('id', $id);
        }

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        $object = $query->firstOrFail();
        Gate::forUser(auth('sanctum')->user())->authorize('view', $object);

        if (property_exists($this->modelClass, 'allowedFields')) {
            $query = $query->allowedFields($this->modelClass::$allowedFields);
        }
        $includeAuthResponse = $this->authorizeIncludes($request);
        if ($includeAuthResponse !== null) {
            return $includeAuthResponse;
        }
        if (property_exists($this->modelClass, 'allowedIncludes')) {
            $query = $query->allowedIncludes($this->modelClass::$allowedIncludes);
        }

        $model = $query->firstOrFail();

        return $model;
    }

    public function update(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        $id = $this->getResourceId($request);
        $organization = request()->get('organization');
        $mismatch = $this->organizationIdMismatchResponse($request, $organization);
        if ($mismatch !== null) {
            return $mismatch;
        }
        $validator = $this->modelClass->validateUpdate($request);
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }
        $validated = $validator->validated();

        $isOrganizationResource = $organization && get_class($organization) === get_class($this->modelClass);
        $query = QueryBuilder::for($this->modelClass::class);
        if (! $isOrganizationResource) {
            $query->where('id', $id);
        }

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        $object = $query->firstOrFail();
        Gate::forUser(auth('sanctum')->user())->authorize('update', $object);

        $object->update($validated);
        $object->refresh();

        return $object;
    }

    public function destroy(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        $id = $this->getResourceId($request);
        $organization = request()->get('organization');
        $mismatch = $this->organizationIdMismatchResponse($request, $organization);
        if ($mismatch !== null) {
            return $mismatch;
        }
        $isOrganizationResource = $organization && get_class($organization) === get_class($this->modelClass);
        $query = QueryBuilder::for($this->modelClass::class);
        if (! $isOrganizationResource) {
            $query->where('id', $id);
        }

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        $object = $query->firstOrFail();
        Gate::forUser(auth('sanctum')->user())->authorize('delete', $object);

        $object->delete();

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------------
    // Soft Delete Endpoints
    // ------------------------------------------------------------------
    // These endpoints are only registered for models that use SoftDeletes.
    // ------------------------------------------------------------------

    /**
     * List soft-deleted (trashed) records.
     */
    public function trashed(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        $this->ensureSoftDeletes();

        Gate::forUser(auth('sanctum')->user())->authorize('viewTrashed', $this->modelClass::class);

        $query = QueryBuilder::for($this->modelClass::class)->onlyTrashed();

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        if (property_exists($this->modelClass, 'allowedFilters')) {
            $query = $query->allowedFilters($this->modelClass::$allowedFilters);
        }
        if (property_exists($this->modelClass, 'defaultSort')) {
            $query = $query->defaultSort($this->modelClass::$defaultSort);
        }
        if (property_exists($this->modelClass, 'allowedSorts')) {
            $query = $query->allowedSorts($this->modelClass::$allowedSorts);
        }
        if (property_exists($this->modelClass, 'allowedFields')) {
            $query = $query->allowedFields($this->modelClass::$allowedFields);
        }
        $includeAuthResponse = $this->authorizeIncludes($request);
        if ($includeAuthResponse !== null) {
            return $includeAuthResponse;
        }
        if (property_exists($this->modelClass, 'allowedIncludes')) {
            $query = $query->allowedIncludes($this->modelClass::$allowedIncludes);
        }

        $this->applySearch($query, $request);

        // Pagination support (same as index)
        $perPage = $request->input('per_page');
        $paginationEnabled = property_exists($this->modelClass, 'paginationEnabled')
            ? $this->modelClass::$paginationEnabled
            : false;

        if ($perPage !== null || $paginationEnabled) {
            $perPage = (int) ($perPage ?? $this->modelClass->getPerPage());
            $perPage = max(1, min($perPage, 100));

            $paginator = $query->paginate($perPage);

            return response()->json($paginator->items())
                ->header('X-Current-Page', $paginator->currentPage())
                ->header('X-Last-Page', $paginator->lastPage())
                ->header('X-Per-Page', $paginator->perPage())
                ->header('X-Total', $paginator->total());
        }

        return $query->get();
    }

    /**
     * Restore a soft-deleted record.
     */
    public function restore(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        $this->ensureSoftDeletes();

        $id = $this->getResourceId($request);
        $organization = request()->get('organization');
        $mismatch = $this->organizationIdMismatchResponse($request, $organization);
        if ($mismatch !== null) {
            return $mismatch;
        }
        $isOrganizationResource = $organization && get_class($organization) === get_class($this->modelClass);
        $query = QueryBuilder::for($this->modelClass::class)->onlyTrashed();
        if (! $isOrganizationResource) {
            $query->where('id', $id);
        }

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        $object = $query->firstOrFail();
        Gate::forUser(auth('sanctum')->user())->authorize('restore', $object);

        $object->restore();
        $object->refresh();

        return $object;
    }

    /**
     * Permanently delete a record (force delete).
     */
    public function forceDelete(Request $request)
    {
        $this->resolveModelClass($this->getModelSlug($request));
        $this->ensureSoftDeletes();

        $id = $this->getResourceId($request);
        $organization = request()->get('organization');
        $mismatch = $this->organizationIdMismatchResponse($request, $organization);
        if ($mismatch !== null) {
            return $mismatch;
        }
        $isOrganizationResource = $organization && get_class($organization) === get_class($this->modelClass);
        $query = QueryBuilder::for($this->modelClass::class)->onlyTrashed();
        if (! $isOrganizationResource) {
            $query->where('id', $id);
        }

        // Apply organization scope if multi-tenant is enabled
        $this->applyOrganizationScope($query);

        $object = $query->firstOrFail();
        Gate::forUser(auth('sanctum')->user())->authorize('forceDelete', $object);

        $object->forceDelete();

        return response()->json(null, 204);
    }

    /**
     * Ensure the model uses SoftDeletes trait.
     */
    protected function ensureSoftDeletes(): void
    {
        if (!in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($this->modelClass))) {
            abort(404, 'This resource does not support soft deletes');
        }
    }

    /**
     * When the resource is Organization, require the route id to match the current organization's key.
     * Returns a 404 JSON response if the user is requesting a different org (avoids leaking existence).
     */
    protected function organizationIdMismatchResponse(Request $request, $organization): ?Response
    {
        if (!$organization || get_class($organization) !== get_class($this->modelClass)) {
            return null;
        }
        $id = $request->route('id');
        if ($id === null) {
            return null;
        }
        if ((string) $organization->getKey() !== (string) $id) {
            return response()->json(['message' => 'Organization not found'], 404);
        }
        return null;
    }

    /**
     * Apply organization scope to query if multi-tenant is enabled.
     */
    protected function applyOrganizationScope($query)
    {
        $config = config('lumina.multi_tenant', []);
        
        if (!($config['enabled'] ?? false)) {
            return;
        }

        $organization = request()->get('organization');
        
        if (!$organization) {
            return;
        }

        // When the resource being queried IS the Organization model, restrict to the current organization only
        if (get_class($organization) === get_class($this->modelClass)) {
            $query->where($organization->getKeyName(), $organization->getKey());
            return;
        }

        // Check if model has a scopeForOrganization method
        if (method_exists($this->modelClass, 'scopeForOrganization')) {
            $query->forOrganization($organization);
            return;
        }
        
        // Check if model has organization_id column (direct relationship)
        if (in_array('organization_id', $this->getModelFillable())) {
            $query->where('organization_id', $organization->id);
            return;
        }

        // Check if model has explicit $owner property defined
        if (property_exists($this->modelClass, 'owner') && !empty($this->modelClass::$owner)) {
            $this->applyOrganizationScopeThroughRelationship($query, $organization, $this->modelClass::$owner);
            return;
        }

        // Fallback: Try to find organization relationship through model relationships (legacy support)
        $organizationPath = $this->findOrganizationRelationshipPath();
        
        if ($organizationPath) {
            $this->applyOrganizationScopeThroughRelationship($query, $organization, $organizationPath);
        }
        // If no organization relationship found, model is global (available to all orgs)
    }

    /**
     * Apply global search when ?search=term is present and model has $allowedSearch.
     * Builds OR WHERE (LOWER(col) LIKE %term%) for each column; dot notation uses whereHas for relationships.
     */
    protected function applySearch($query, Request $request): void
    {
        if (! property_exists($this->modelClass, 'allowedSearch')) {
            return;
        }

        $searchTerm = $request->input('search');
        if ($searchTerm === null || $searchTerm === '') {
            return;
        }

        $columns = $this->modelClass::$allowedSearch;
        $term = '%'.strtolower((string) $searchTerm).'%';

        $query->where(function ($q) use ($columns, $term) {
            foreach ($columns as $column) {
                if (str_contains($column, '.')) {
                    [$relation, $field] = explode('.', $column, 2);
                    $q->orWhereHas($relation, function ($sub) use ($field, $term) {
                        $sub->whereRaw('LOWER('.$field.') LIKE ?', [$term]);
                    });
                } else {
                    $q->orWhereRaw('LOWER('.$column.') LIKE ?', [$term]);
                }
            }
        });
    }

    /**
     * Authorize requested ?include= relationships.
     * For each requested include that is in the model's allowedIncludes, checks that the user
     * has viewAny permission on the related model(s). For nested includes (e.g. blog.posts),
     * each segment is authorized. If the user cannot view the related resource, returns a 403
     * JSON response (message only; no exception or stack trace).
     *
     * @return Response|null 403 JSON response when unauthorized, null when authorized
     */
    protected function authorizeIncludes(Request $request): ?Response
    {
        $includeParam = $request->input('include');
        if ($includeParam === null || $includeParam === '') {
            return null;
        }

        $requestedIncludes = array_filter(array_map('trim', explode(',', $includeParam)));
        if (empty($requestedIncludes)) {
            return null;
        }

        $allowedIncludes = property_exists($this->modelClass, 'allowedIncludes')
            ? $this->modelClass::$allowedIncludes
            : [];
        if (empty($allowedIncludes)) {
            return null;
        }

        $user = auth('sanctum')->user();

        foreach ($requestedIncludes as $includePath) {
            $segments = explode('.', $includePath);
            $currentModel = $this->modelClass;
            $currentAllowedIncludes = $allowedIncludes;

            foreach ($segments as $segment) {
                $resolvedSegment = $this->resolveBaseIncludeSegment($segment, $currentAllowedIncludes);
                if ($resolvedSegment === null) {
                    continue 2;
                }

                $relation = $currentModel->{$resolvedSegment}();
                $relatedModelClass = get_class($relation->getRelated());

                $response = Gate::forUser($user)->inspect('viewAny', $relatedModelClass);
                if ($response->denied()) {
                    return response()->json([
                        'message' => "You do not have permission to include {$includePath}.",
                    ], 403);
                }

                $currentModel = $relation->getRelated();
                $currentAllowedIncludes = property_exists($currentModel, 'allowedIncludes')
                    ? $currentModel::$allowedIncludes
                    : [];
            }
        }

        return null;
    }

    /**
     * Resolve an include segment to the base relationship name for authorization.
     * Handles Count/Exists suffixes so that e.g. postsCount is authorized like posts.
     *
     * @param  array<string>  $allowedIncludes
     */
    protected function resolveBaseIncludeSegment(string $segment, array $allowedIncludes): ?string
    {
        if (in_array($segment, $allowedIncludes)) {
            return $segment;
        }

        $countSuffix = config('query-builder.count_suffix', 'Count');
        $existsSuffix = config('query-builder.exists_suffix', 'Exists');

        if ($countSuffix !== '' && str_ends_with($segment, $countSuffix)) {
            $base = substr($segment, 0, -strlen($countSuffix));
            if (in_array($base, $allowedIncludes)) {
                return $base;
            }
        }

        if ($existsSuffix !== '' && str_ends_with($segment, $existsSuffix)) {
            $base = substr($segment, 0, -strlen($existsSuffix));
            if (in_array($base, $allowedIncludes)) {
                return $base;
            }
        }

        return null;
    }

    /**
     * Find the relationship path to Organization model.
     * Returns the relationship method name if found, null otherwise.
     */
    protected function findOrganizationRelationshipPath(): ?string
    {
        // Create model instance and ensure it has a connection
        $modelInstance = new $this->modelClass;
        
        // Ensure the model instance has a connection to avoid errors when calling relationship methods
        if (!$modelInstance->getConnection()) {
            $modelInstance->setConnection($modelInstance->getConnectionName() ?: config('database.default'));
        }
        
        // Check for direct organization relationship
        if (method_exists($modelInstance, 'organization')) {
            return 'organization';
        }
        
        // Check for organizations (many-to-many or has-many)
        if (method_exists($modelInstance, 'organizations')) {
            return 'organizations';
        }
        
        // No organization relationship found
        // Models should use the $owner property to explicitly define the relationship path
        return null;
    }

    /**
     * Apply organization scope through a relationship path.
     * Supports dot-separated paths (e.g., 'post.blog') for nested relationships.
     * Automatically traverses the path to find organization.
     */
    protected function applyOrganizationScopeThroughRelationship($query, $organization, string $relationshipPath)
    {
        // Handle dot-separated paths (e.g., 'post.blog')
        if (strpos($relationshipPath, '.') !== false) {
            // For nested paths, recursively build the path to organization
            $this->applyNestedOrganizationScope($query, $organization, $relationshipPath);
            return;
        }
        
        // Single relationship path (non-nested)
        $modelInstance = new $this->modelClass;
        
        // Check if the relationship method exists
        if (!method_exists($modelInstance, $relationshipPath)) {
            return;
        }
        
        try {
            $relation = $modelInstance->$relationshipPath();
        } catch (\Exception $e) {
            return; // Skip if relationship can't be resolved
        }
        
        if ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsToMany) {
            // Many-to-many relationship
            $query->whereHas($relationshipPath, function ($q) use ($organization) {
                $q->where('organizations.id', $organization->id);
            });
        } elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\HasMany || 
                  $relation instanceof \Illuminate\Database\Eloquent\Relations\HasOne) {
            // Has-many or has-one - check if related model has organization_id
            $relatedModel = get_class($relation->getRelated());
            $relatedInstance = new $relatedModel;
            
            if (in_array('organization_id', $relatedInstance->getFillable())) {
                $query->whereHas($relationshipPath, function ($q) use ($organization) {
                    $q->where('organization_id', $organization->id);
                });
            } elseif (property_exists($relatedModel, 'owner') && !empty($relatedModel::$owner)) {
                // Related model has $owner property, recursively traverse
                $this->applyOrganizationScopeThroughRelationship($query, $organization, $relationshipPath . '.' . $relatedModel::$owner);
            } elseif (method_exists($relatedModel, 'organization') || method_exists($relatedModel, 'organizations')) {
                // Related model has organization relationship, traverse further
                $query->whereHas($relationshipPath . '.organization', function ($q) use ($organization) {
                    $q->where('organizations.id', $organization->id);
                });
            }
        } elseif ($relation instanceof \Illuminate\Database\Eloquent\Relations\BelongsTo) {
            // Belongs-to relationship - check if related model is Organization
            $relatedModel = get_class($relation->getRelated());
            
            if ($relatedModel === \App\Models\Organization::class) {
                $query->where($relation->getForeignKeyName(), $organization->id);
            } else {
                // Related model might have organization relationship or $owner property
                $relatedInstance = new $relatedModel;
                if (in_array('organization_id', $relatedInstance->getFillable())) {
                    $query->whereHas($relationshipPath, function ($q) use ($organization) {
                        $q->where('organization_id', $organization->id);
                    });
                } elseif (property_exists($relatedModel, 'owner') && !empty($relatedModel::$owner)) {
                    // Related model has $owner property, recursively traverse
                    $this->applyOrganizationScopeThroughRelationship($query, $organization, $relationshipPath . '.' . $relatedModel::$owner);
                }
            }
        }
    }
    
    /**
     * Build the full relationship path to organization by following $owner properties.
     * Returns the full dot-separated path (e.g., 'post.blog.organization').
     */
    protected function buildOrganizationPath(string $startPath): ?string
    {
        $modelInstance = new $this->modelClass;
        $currentPath = $startPath;
        $visited = []; // Prevent infinite loops
        
        while (true) {
            // Get the last relationship name in the path
            $pathParts = explode('.', $currentPath);
            $lastPart = end($pathParts);
            
            // Check if we've visited this path before (infinite loop prevention)
            if (in_array($currentPath, $visited)) {
                return null;
            }
            $visited[] = $currentPath;
            
            // Traverse the path to get the related model
            $currentModel = $this->modelClass;
            $tempInstance = new $currentModel;
            
            foreach ($pathParts as $part) {
                if (!method_exists($tempInstance, $part)) {
                    return null;
                }
                try {
                    $relation = $tempInstance->$part();
                    if (!$relation instanceof \Illuminate\Database\Eloquent\Relations\Relation) {
                        return null;
                    }
                    $currentModel = get_class($relation->getRelated());
                    $tempInstance = new $currentModel;
                } catch (\Exception $e) {
                    return null;
                }
            }
            
            // Check if we've reached Organization
            if ($currentModel === \App\Models\Organization::class) {
                return $currentPath;
            }
            
            // Check if current model has organization_id
            $currentInstance = new $currentModel;
            if (in_array('organization_id', $currentInstance->getFillable())) {
                return $currentPath . '.organization';
            }
            
            // Check if current model has $owner property
            if (property_exists($currentModel, 'owner') && !empty($currentModel::$owner)) {
                $currentPath .= '.' . $currentModel::$owner;
                continue;
            }
            
            // Check if current model has organization relationship
            if (method_exists($currentModel, 'organization')) {
                return $currentPath . '.organization';
            }
            
            // Can't find organization path
            return null;
        }
    }
    
    /**
     * Apply organization scope for nested relationship paths (e.g., 'post.blog').
     * Builds the full path to organization and applies whereHas.
     */
    protected function applyNestedOrganizationScope($query, $organization, string $path)
    {
        $fullPath = $this->buildOrganizationPath($path);
        
        if (!$fullPath) {
            return; // Couldn't build path to organization
        }
        
        // Check if the path ends with organization or organization_id
        if (str_ends_with($fullPath, '.organization') || str_ends_with($fullPath, '.organizations')) {
            $query->whereHas($fullPath, function ($q) use ($organization) {
                $q->where('organizations.id', $organization->id);
            });
        } else {
            // Path ends with a model that has organization_id
            $query->whereHas($fullPath, function ($q) use ($organization) {
                $q->where('organization_id', $organization->id);
            });
        }
    }

    /**
     * Add organization_id to data if multi-tenant is enabled.
     */
    protected function addOrganizationToData(array &$data)
    {
        $config = config('lumina.multi_tenant', []);
        
        if (!($config['enabled'] ?? false)) {
            return;
        }

        $organization = request()->get('organization');
        
        if ($organization && in_array('organization_id', $this->getModelFillable())) {
            $data['organization_id'] = $organization->id;
        }
    }

    /**
     * Get fillable attributes of the model.
     */
    protected function getModelFillable(): array
    {
        $modelInstance = new $this->modelClass;
        return $modelInstance->getFillable();
    }

    // ------------------------------------------------------------------
    // Nested create/update endpoint
    // ------------------------------------------------------------------

    /**
     * Execute multiple create/update operations in one request (single transaction).
     * Request body: { "operations": [ { "model", "action", "id?" , "data" }, ... ] }
     * Response: { "results": [ { "model", "action", "id", "data" }, ... ] } with full model content in data.
     */
    public function nested(Request $request)
    {
        $operations = $this->validateNestedStructure($request);
        if ($operations instanceof \Illuminate\Http\JsonResponse) {
            return $operations;
        }
        $nestedConfig = config('lumina.nested', []);
        $maxOps = $nestedConfig['max_operations'] ?? null;
        if ($maxOps !== null && count($operations) > (int) $maxOps) {
            return response()->json([
                'message' => 'Too many operations.',
                'errors' => ['operations' => ['Maximum ' . $maxOps . ' operations allowed.']],
            ], 422);
        }
        $allowedModels = $nestedConfig['allowed_models'] ?? null;
        if (is_array($allowedModels)) {
            foreach ($operations as $index => $op) {
                if (!in_array($op['model'], $allowedModels)) {
                    return response()->json([
                        'message' => 'Operation not allowed.',
                        'errors' => ['operations.' . $index . '.model' => ['Model "' . $op['model'] . '" is not allowed for nested operations.']],
                    ], 422);
                }
            }
        }

        $validatedPerOp = [];
        $authResults = []; // for create: null; for update: the loaded model instance
        foreach ($operations as $index => $operation) {
            $validated = $this->validateNestedOperation($operation, $index);
            if ($validated instanceof \Illuminate\Http\JsonResponse) {
                return $validated;
            }
            $validatedPerOp[$index] = $validated;

            $authResult = $this->authorizeNestedOperation($operation, $validated, $index);
            if ($authResult instanceof \Illuminate\Http\JsonResponse) {
                return $authResult;
            }
            $authResults[$index] = $authResult;
        }

        $results = $this->executeNestedOperations($operations, $validatedPerOp, $authResults);
        return response()->json(['results' => $results]);
    }

    /**
     * Validate request structure: operations present, array, each has model, action, data; id required for update.
     * Returns the operations array or returns a 422 JsonResponse.
     */
    protected function validateNestedStructure(Request $request)
    {
        $data = $request->all();
        if (!isset($data['operations']) || !is_array($data['operations'])) {
            return response()->json([
                'message' => 'The operations field is required and must be an array.',
                'errors' => ['operations' => ['The operations field is required and must be an array.']],
            ], 422);
        }
        $operations = $data['operations'];
        foreach ($operations as $index => $op) {
            if (!is_array($op)) {
                return response()->json([
                    'message' => 'Invalid structure.',
                    'errors' => ['operations.' . $index => ['Each operation must be an object.']],
                ], 422);
            }
            if (empty($op['model']) || !is_string($op['model'])) {
                return response()->json([
                    'message' => 'Invalid structure.',
                    'errors' => ['operations.' . $index . '.model' => ['The model field is required.']],
                ], 422);
            }
            if (empty($op['action']) || !in_array($op['action'], ['create', 'update'])) {
                return response()->json([
                    'message' => 'Invalid structure.',
                    'errors' => ['operations.' . $index . '.action' => ['The action must be create or update.']],
                ], 422);
            }
            if (!isset($op['data']) || !is_array($op['data'])) {
                return response()->json([
                    'message' => 'Invalid structure.',
                    'errors' => ['operations.' . $index . '.data' => ['The data field is required and must be an object.']],
                ], 422);
            }
            if (($op['action'] ?? '') === 'update') {
                if (!array_key_exists('id', $op)) {
                    return response()->json([
                        'message' => 'Invalid structure.',
                        'errors' => ['operations.' . $index . '.id' => ['The id field is required for update operations.']],
                    ], 422);
                }
            }
        }
        return $operations;
    }

    /**
     * Validate a single operation's data using the model's validateStore/validateUpdate.
     * Returns validated array or a 422 JsonResponse.
     */
    protected function validateNestedOperation(array $operation, int $index)
    {
        $slug = $operation['model'];
        if (!isset(config('lumina.models')[$slug])) {
            return response()->json([
                'message' => 'Unknown model.',
                'errors' => ['operations.' . $index . '.model' => ['The model "' . $slug . '" does not exist.']],
            ], 422);
        }
        $this->resolveModelClass($slug);
        $modelClass = $this->modelClass;
        $subRequest = Request::create('', 'POST', $operation['data'], [], [], [], []);
        if ($operation['action'] === 'create') {
            $validator = $modelClass->validateStore($subRequest);
        } else {
            $validator = $modelClass->validateUpdate($subRequest);
        }
        if ($validator->fails()) {
            $errors = [];
            foreach ($validator->errors()->messages() as $key => $messages) {
                $errors['operations.' . $index . '.data.' . $key] = $messages;
            }
            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }
        return $validator->validated();
    }

    /**
     * Authorize a single operation (create or update). For create returns null; for update returns the model instance.
     * Returns null or the model instance, or a 403/404 JsonResponse.
     */
    protected function authorizeNestedOperation(array $operation, array $validated, int $index)
    {
        $slug = $operation['model'];
        $this->resolveModelClass($slug);
        $modelClass = $this->modelClass;
        $user = auth('sanctum')->user();

        if ($operation['action'] === 'create') {
            Gate::forUser($user)->authorize('create', $modelClass);
            return null;
        }

        $query = QueryBuilder::for($modelClass::class)->where('id', $operation['id']);
        $this->applyOrganizationScope($query);
        try {
            $object = $query->firstOrFail();
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['message' => 'Resource not found.'], 404);
        }
        Gate::forUser($user)->authorize('update', $object);
        return $object;
    }

    /**
     * Execute all operations inside a single DB transaction. Returns results array with model, action, id, data (full model).
     */
    protected function executeNestedOperations(array $operations, array $validatedPerOp, array $authResults): array
    {
        $results = [];
        DB::transaction(function () use ($operations, $validatedPerOp, $authResults, &$results) {
            foreach (array_keys($operations) as $index) {
                $op = $operations[$index];
                $validated = $validatedPerOp[$index];
                $modelOrNull = $authResults[$index];

                if ($op['action'] === 'create') {
                    $this->resolveModelClass($op['model']);
                    $data = $validated;
                    $this->addOrganizationToData($data);
                    $model = $this->modelClass::create($data);
                    $results[] = [
                        'model' => $op['model'],
                        'action' => 'create',
                        'id' => $model->getKey(),
                        'data' => $model->toArray(),
                    ];
                } else {
                    $object = $modelOrNull;
                    $object->update($validated);
                    $object->refresh();
                    $results[] = [
                        'model' => $op['model'],
                        'action' => 'update',
                        'id' => $object->getKey(),
                        'data' => $object->toArray(),
                    ];
                }
            }
        });
        return $results;
    }
}
