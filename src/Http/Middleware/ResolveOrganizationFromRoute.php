<?php

namespace Lumina\LaravelApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Organization;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganizationFromRoute
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only process if the route has an organization parameter
        $route = $request->route();
        if (!$route || !$route->hasParameter('organization')) {
            return $next($request);
        }

        $organizationIdentifier = $route->parameter('organization');

        if (!$organizationIdentifier) {
            return response()->json(['message' => 'Organization identifier is required'], 400);
        }

        $identifierColumn = config('lumina.multi_tenant.organization_identifier_column', 'id');
        
        $organization = Organization::where($identifierColumn, $organizationIdentifier)->first();

        if (!$organization) {
            return response()->json(['message' => 'Organization not found'], 404);
        }

        // Check if user is authenticated and belongs to this organization
        $user = $request->user('sanctum');
        
        if ($user) {
            $userBelongsToOrg = $user->organizations()
                ->where('organizations.id', $organization->id)
                ->exists();
            
            if (!$userBelongsToOrg) {
                return response()->json(['message' => 'Organization not found'], 404);
            }
        }

        // Set organization in request for later use
        $request->merge(['organization' => $organization]);
        $request->attributes->set('organization', $organization);

        return $next($request);
    }
}
