<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $organization = Organization::query()->orderBy('id')->first()
            ?? Organization::query()->create(['name' => 'Platform storage']);
        $request->session()->put('organization_id', $organization->id);
        $request->attributes->set('organization', $organization);

        return $next($request);
    }
}
