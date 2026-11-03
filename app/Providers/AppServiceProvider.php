<?php

namespace App\Providers;

use App\Models\Product;
use App\Services\ApprovedCatalogImporter;
use App\Services\DocumentExtractor;
use App\Services\FayzToolsCatalogImporter;
use App\Services\FileScanner;
use App\Services\HttpDocumentExtractor;
use App\Services\QueueWorkerHeartbeat;
use App\Services\UnavailableFileScanner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerIdle;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DocumentExtractor::class, HttpDocumentExtractor::class);
        $this->app->bind(FileScanner::class, UnavailableFileScanner::class);
        $this->app->singleton(QueueWorkerHeartbeat::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            Vite::useHotFile(storage_path('framework/production-vite.hot'));
        }

        $this->ensureCatalogBootstrap();

        RateLimiter::for('ai-assistant', fn (Request $request) => Limit::perMinute(6)->by('ai:'.($request->user()?->id ?? $request->ip())));
        Event::listen([WorkerIdle::class, JobProcessing::class], [QueueWorkerHeartbeat::class, 'record']);
    }

    protected function ensureCatalogBootstrap(): void
    {
        if (! Schema::hasTable('products') || ! Schema::hasTable('organizations') || ! Schema::hasTable('offers')) {
            return;
        }

        if (Product::query()->exists()) {
            return;
        }

        try {
            $this->app->make(ApprovedCatalogImporter::class)->import();
        } catch (\Throwable) {
            // Ignore missing or malformed internal datasets during non-seeded local bootstraps.
        }

        try {
            $this->app->make(FayzToolsCatalogImporter::class)->import();
        } catch (\Throwable) {
            // Ignore missing optional export snapshots when the tenant data directory is absent.
        }
    }
}
