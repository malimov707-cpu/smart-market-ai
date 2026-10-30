<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Session\Middleware\StartSession;

class CatalogSession extends StartSession
{
    /** Downloads and lookups must not become a form validation return target. */
    protected function storeCurrentUrl(mixed $request, mixed $session): void
    {
        if ($request->routeIs('managed-imports.file', 'managed-imports.errors', 'catalog.files.download', 'products.lookup', 'suppliers.lookup', 'products.export', 'product-imports.errors')) {
            $session->reflash();

            return;
        }
        parent::storeCurrentUrl($request, $session);
    }
}
