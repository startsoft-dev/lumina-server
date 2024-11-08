<?php

namespace Lumina\LaravelApi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Organization;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganizationFromSubdomain
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $subdomain = explode('.', $host)[0];

        // Skip if it's the main domain (www, app, etc.)
        if (in_array($subdomain, ['www', 'app', 'api', 'localhost', '127.0.0.1'])) {
            return $next($request);
        }

        $identifierColumn = config('lumina.multi_tenant.organization_identifier_column', 'slug');
        
        $organization = Organization::where('domain', $host)
            ->orWhere($identifierColumn, $subdomain)
            ->first();

        if (!$organization) {
            abort(404, 'Organization not found');
        }

        // Check if user is authenticated and belongs to this organization
        $user = $request->user('sanctum');
        
        if ($user) {
            $userBelongsToOrg = $user->organizations()
                ->where('organizations.id', $organization->id)
                ->exists();
            
            if (!$userBelongsToOrg) {
                abort(403, 'You do not have access to this organization');
            }
        }

        // Set organization in request for later use
        $request->merge(['organization' => $organization]);
        $request->attributes->set('organization', $organization);

        return $next($request);
    }
}
