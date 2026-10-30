<?php

namespace App\Providers;

use App\Services\DocumentExtractor;
use App\Services\FileScanner;
use App\Services\HttpDocumentExtractor;
use App\Services\UnavailableFileScanner;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
