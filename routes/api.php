<?php

use Illuminate\Support\Facades\Route;
use Lumina\LaravelApi\Controllers\AuthController;
use Lumina\LaravelApi\Controllers\GlobalController;
use Lumina\LaravelApi\Controllers\InvitationController;

/*
|--------------------------------------------------------------------------
| Auth Routes
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('password/recover', [AuthController::class, 'recoverPassword']);
    Route::post('password/reset', [AuthController::class, 'reset']);
    Route::post('register', [AuthController::class, 'registerWithInvitation']);
    Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
});

/*
|--------------------------------------------------------------------------
| Invitation Routes
|--------------------------------------------------------------------------
*/

Route::post('invitations/accept', [InvitationController::class, 'accept']);

/*
|--------------------------------------------------------------------------
| Auto-Generated CRUD Routes
|--------------------------------------------------------------------------
|
| Routes are generated per model from the global-controller config.
| Each model gets explicit routes visible in `php artisan route:list`.
|
| To override a specific action, define your custom route ABOVE this file's
| require statement in routes/api.php. The first registered route wins.
|
| Models can define:
|   - public static array $middleware = ['throttle:60,1'];
|   - public static array $middlewareActions = ['store' => ['verified']];
|   - public static array $exceptActions = ['delete'];
|
*/

$globalControllerConfig = config('lumina', []);
$multiTenant = $globalControllerConfig['multi_tenant'] ?? [];
$isMultiTenant = $multiTenant['enabled'] ?? false;
$useSubdomain = $multiTenant['use_subdomain'] ?? false;
$multiTenantMiddleware = !empty($multiTenant['middleware']) ? $multiTenant['middleware'] : null;
$models = $globalControllerConfig['models'] ?? [];
$publicModels = $globalControllerConfig['public'] ?? [];

// Multi-tenant with route prefix uses {organization} in the URL
$needsOrgPrefix = $isMultiTenant && !$useSubdomain;

// Invitation routes (protected, require auth + organization context)
if ($isMultiTenant) {
    $invitationMiddleware = array_filter(['auth:sanctum', $multiTenantMiddleware]);
    $invitationPrefix = $needsOrgPrefix ? '{organization}/invitations' : 'invitations';

    Route::prefix($invitationPrefix)
        ->middleware($invitationMiddleware)
        ->group(function () {
            Route::get('/', [InvitationController::class, 'index']);
            Route::post('/', [InvitationController::class, 'store']);
            Route::post('{id}/resend', [InvitationController::class, 'resend']);
            Route::delete('{id}', [InvitationController::class, 'cancel']);
        });
}

// Nested create/update endpoint (one request, multiple operations, single transaction)
$nestedConfig = $globalControllerConfig['nested'] ?? [];
$nestedPath = $nestedConfig['path'] ?? 'nested';
$nestedMiddleware = array_filter(['auth:sanctum', $multiTenantMiddleware]);
$nestedPrefix = $needsOrgPrefix ? "{organization}/{$nestedPath}" : $nestedPath;
Route::post($nestedPrefix, [GlobalController::class, 'nested'])
    ->middleware($nestedMiddleware)
    ->name('nested');

// Register per-model CRUD routes
foreach ($models as $slug => $modelClass) {
    if (!class_exists($modelClass)) {
        continue;
    }

    // Base middleware
    $middleware = [];

    if (!in_array($slug, $publicModels)) {
        $middleware[] = 'auth:sanctum';
    }

    // Multi-tenant middleware (organization resolver)
    if ($isMultiTenant && $multiTenantMiddleware) {
        $middleware[] = $multiTenantMiddleware;
    }

    // Model-level middleware (applied to all actions)
    if (property_exists($modelClass, 'middleware')) {
        $middleware = array_merge($middleware, $modelClass::$middleware);
    }

    // Per-action middleware
    $actionMiddleware = property_exists($modelClass, 'middlewareActions')
        ? $modelClass::$middlewareActions
        : [];

    // Excepted actions (actions to skip)
    $exceptActions = property_exists($modelClass, 'exceptActions')
        ? $modelClass::$exceptActions
        : [];

    // Build route prefix based on multi-tenant mode
    $prefix = $needsOrgPrefix ? "{organization}/{$slug}" : $slug;

    // Check if the model uses SoftDeletes
    $usesSoftDeletes = in_array(
        \Illuminate\Database\Eloquent\SoftDeletes::class,
        class_uses_recursive($modelClass)
    );

    Route::prefix($prefix)
        ->middleware($middleware)
        ->group(function () use ($slug, $actionMiddleware, $exceptActions, $usesSoftDeletes) {
            if (!in_array('index', $exceptActions)) {
                Route::get('/', [GlobalController::class, 'index'])
                    ->defaults('model', $slug)
                    ->middleware($actionMiddleware['index'] ?? [])
                    ->name("{$slug}.index");
            }

            if (!in_array('store', $exceptActions)) {
                Route::post('/', [GlobalController::class, 'store'])
                    ->defaults('model', $slug)
                    ->middleware($actionMiddleware['store'] ?? [])
                    ->name("{$slug}.store");
            }

            // Soft Delete routes — registered BEFORE {id} routes to avoid wildcard capture
            if ($usesSoftDeletes) {
                if (!in_array('trashed', $exceptActions)) {
                    Route::get('trashed', [GlobalController::class, 'trashed'])
                        ->defaults('model', $slug)
                        ->middleware($actionMiddleware['trashed'] ?? [])
                        ->name("{$slug}.trashed");
                }

                if (!in_array('restore', $exceptActions)) {
                    Route::post('{id}/restore', [GlobalController::class, 'restore'])
                        ->defaults('model', $slug)
                        ->middleware($actionMiddleware['restore'] ?? [])
                        ->name("{$slug}.restore");
                }

                if (!in_array('forceDelete', $exceptActions)) {
                    Route::delete('{id}/force-delete', [GlobalController::class, 'forceDelete'])
                        ->defaults('model', $slug)
                        ->middleware($actionMiddleware['forceDelete'] ?? [])
                        ->name("{$slug}.forceDelete");
                }
            }

            if (!in_array('show', $exceptActions)) {
                Route::get('{id}', [GlobalController::class, 'show'])
                    ->defaults('model', $slug)
                    ->middleware($actionMiddleware['show'] ?? [])
                    ->name("{$slug}.show");
            }

            if (!in_array('update', $exceptActions)) {
                Route::put('{id}', [GlobalController::class, 'update'])
                    ->defaults('model', $slug)
                    ->middleware($actionMiddleware['update'] ?? [])
                    ->name("{$slug}.update");
            }

            if (!in_array('destroy', $exceptActions)) {
                Route::delete('{id}', [GlobalController::class, 'destroy'])
                    ->defaults('model', $slug)
                    ->middleware($actionMiddleware['destroy'] ?? [])
                    ->name("{$slug}.destroy");
            }
        });
}
