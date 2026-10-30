<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $organizations = $request->user()->organizations();
        $selected = $request->session()->get('organization_id');
        $organization = $selected
            ? $organizations->where('organizations.id', $selected)->first()
            : $organizations->orderBy('organizations.id')->first();
        abort_unless($organization, 403, 'No active organization membership.');
        $request->session()->put('organization_id', $organization->id);
        $request->attributes->set('organization', $organization);

        return $next($request);
    }
}
