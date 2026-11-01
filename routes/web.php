<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AiAssistantController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CatalogFileController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ManagedImportController;
use App\Http\Controllers\MarketController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PriceIntelligenceController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\PublicMarketController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\VerificationController;
use App\Http\Middleware\EnsureActiveAccount;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\ResolveOrganization;
use Illuminate\Support\Facades\Route;

Route::get('/', [AuthController::class, 'home']);
Route::middleware('guest')->group(function (): void {
    Route::post('/account/reactivate', [AccountController::class, 'reactivate'])->middleware('throttle:3,1')->name('account.reactivate');
    Route::get('/forgot-password', [PasswordController::class, 'request'])->name('password.request');
    Route::post('/forgot-password', [PasswordController::class, 'send'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', [PasswordController::class, 'reset'])->name('password.reset');
    Route::post('/reset-password', [PasswordController::class, 'update'])->middleware('throttle:6,1')->name('password.update');
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::get('/register', [AuthController::class, 'show'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:3,1');
});
Route::middleware(['auth', EnsureActiveAccount::class])->group(function (): void {
    Route::get('/verify-email', [VerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/verify-email/{id}/{hash}', [VerificationController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [VerificationController::class, 'send'])->middleware('throttle:3,1')->name('verification.send');
    Route::get('/account', [AccountController::class, 'show'])->name('account');
    Route::post('/account/profile', [AccountController::class, 'profile'])->middleware('throttle:10,1')->name('account.profile');
    Route::post('/account/password', [AccountController::class, 'password'])->middleware('throttle:6,1')->name('account.password');
    Route::post('/account/sessions/revoke', [AccountController::class, 'revokeSessions'])->middleware('throttle:6,1')->name('account.sessions');
    Route::post('/account/sessions/{key}/revoke', [AccountController::class, 'revokeSession'])->middleware('throttle:6,1')->name('account.session');
    Route::post('/account/deactivate', [AccountController::class, 'deactivate'])->middleware('throttle:3,1')->name('account.deactivate');
    Route::get('/invitations/{token}', [InvitationController::class, 'show'])->middleware('verified')->name('invitations.show');
    Route::post('/invitations/{token}/accept', [InvitationController::class, 'accept'])->middleware(['verified', 'throttle:6,1'])->name('invitations.accept');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::post('/organization', [AuthController::class, 'switchOrganization'])->name('organization.switch');
    Route::middleware(['verified', ResolveOrganization::class])->group(function (): void {
        foreach (['dashboard', 'offers', 'comparison', 'imports', 'ai'] as $page) {
            Route::get('/'.$page, [MarketController::class, 'index'])->name($page);
        }
        Route::get('/import-center', [ManagedImportController::class, 'index'])->name('managed-imports');
        Route::post('/import-center', [ManagedImportController::class, 'store'])->middleware('throttle:10,1')->name('managed-imports.store');
        Route::get('/import-center/{import}', [ManagedImportController::class, 'show'])->name('managed-imports.show');
        Route::post('/import-center/{import}/mapping', [ManagedImportController::class, 'mapping'])->name('managed-imports.mapping');
        Route::post('/import-center/{import}/rows/{row}', [ManagedImportController::class, 'correct'])->name('managed-imports.correct');
        Route::post('/import-center/{import}/confirm', [ManagedImportController::class, 'confirm'])->middleware('throttle:10,1')->name('managed-imports.confirm');
        Route::post('/import-center/{import}/cancel', [ManagedImportController::class, 'cancel'])->name('managed-imports.cancel');
        Route::post('/import-center/{import}/retry', [ManagedImportController::class, 'retry'])->middleware('throttle:6,1')->name('managed-imports.retry');
        Route::post('/import-center/{import}/sheet', [ManagedImportController::class, 'sheet'])->name('managed-imports.sheet');
        Route::post('/import-center/{import}/manual', [ManagedImportController::class, 'manual'])->middleware('throttle:20,1')->name('managed-imports.manual');
        Route::get('/import-center/{import}/file', [ManagedImportController::class, 'file'])->name('managed-imports.file');
        Route::get('/import-center/{import}/errors', [ManagedImportController::class, 'errors'])->name('managed-imports.errors');
        Route::get('/products', [CatalogController::class, 'index'])->name('products');
        Route::get('/suppliers', [SupplierController::class, 'index'])->name('suppliers');
        Route::get('/catalog/products/lookup', [CatalogController::class, 'lookup'])->name('products.lookup');
        Route::get('/catalog/suppliers/lookup', [SupplierController::class, 'lookup'])->name('suppliers.lookup');
        Route::get('/catalog/products/export', [ProductImportController::class, 'export'])->name('products.export');
        Route::get('/catalog/product-imports', [ProductImportController::class, 'index'])->name('product-imports');
        Route::post('/catalog/product-imports', [ProductImportController::class, 'upload'])->middleware('throttle:10,1')->name('product-imports.upload');
        Route::get('/catalog/product-imports/{import}', [ProductImportController::class, 'show'])->name('product-imports.show');
        Route::post('/catalog/product-imports/{import}/mapping', [ProductImportController::class, 'mapping'])->name('product-imports.mapping');
        Route::post('/catalog/product-imports/{import}/confirm', [ProductImportController::class, 'confirm'])->name('product-imports.confirm');
        Route::get('/catalog/product-imports/{import}/errors', [ProductImportController::class, 'errors'])->name('product-imports.errors');
        Route::get('/products/{product}', [CatalogController::class, 'show'])->name('products.show');
        Route::post('/products/{product}', [CatalogController::class, 'update'])->name('products.update');
        Route::post('/products/{product}/restore', [CatalogController::class, 'restore'])->name('products.restore');
        Route::post('/products/{product}/images', [CatalogFileController::class, 'store'])->name('products.images');
        Route::get('/suppliers/{supplier}', [SupplierController::class, 'show'])->name('suppliers.show');
        Route::post('/suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
        Route::post('/suppliers/{supplier}/archive', [SupplierController::class, 'archive'])->name('suppliers.archive');
        Route::post('/suppliers/{supplier}/restore', [SupplierController::class, 'restore'])->name('suppliers.restore');
        Route::post('/suppliers/{supplier}/documents', [CatalogFileController::class, 'store'])->name('suppliers.documents');
        Route::post('/suppliers/{supplier}/verification', [SupplierController::class, 'verification'])->name('suppliers.verification');
        Route::post('/suppliers/{supplier}/reviews', [SupplierController::class, 'review'])->name('suppliers.reviews');
        Route::post('/suppliers/{supplier}/reviews/{review}/dispute', [SupplierController::class, 'dispute'])->name('suppliers.dispute');
        Route::post('/suppliers/{supplier}/disputes/{dispute}/resolve', [SupplierController::class, 'resolve'])->name('suppliers.resolve');
        Route::get('/catalog/files/{file}', [CatalogFileController::class, 'download'])->name('catalog.files.download');
        Route::post('/catalog/files/{file}/retire', [CatalogFileController::class, 'retire'])->name('catalog.files.retire');
        Route::get('/settings', [OrganizationController::class, 'show'])->name('settings');
        Route::post('/settings', [OrganizationController::class, 'update'])->name('settings.update');
        Route::post('/settings/invitations', [InvitationController::class, 'store'])->middleware('throttle:10,1')->name('invitations.store');
        Route::post('/settings/invitations/{invitation}/revoke', [InvitationController::class, 'revoke'])->name('invitations.revoke');
        Route::post('/settings/members/{member}/role', [OrganizationController::class, 'role'])->name('members.update');
        Route::post('/settings/members/{member}/remove', [OrganizationController::class, 'remove'])->name('members.remove');
        Route::post('/products', [CatalogController::class, 'store'])->name('products.store');
        Route::post('/products/{product}/archive', [CatalogController::class, 'archive'])->name('products.archive');
        Route::post('/suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::post('/offers', [MarketController::class, 'storeOffer'])->name('offers.store');
        Route::get('/prices/{offer}', [PriceIntelligenceController::class, 'show'])->name('prices.show');
        Route::post('/prices/{offer}/review', [PriceIntelligenceController::class, 'review'])->middleware('throttle:20,1')->name('prices.review');
        Route::get('/products/{product}/price-history', [PriceIntelligenceController::class, 'history'])->name('prices.history');
        Route::get('/exchange-rates', [PriceIntelligenceController::class, 'rates'])->name('exchange-rates');
        Route::post('/exchange-rates', [PriceIntelligenceController::class, 'storeRate'])->middleware('throttle:10,1')->name('exchange-rates.store');
        Route::post('/exchange-rates/{rate}/approve', [PriceIntelligenceController::class, 'approveRate'])->middleware('throttle:10,1')->name('exchange-rates.approve');
        Route::get('/compare', [MarketController::class, 'compare'])->name('compare');
        Route::post('/imports', [MarketController::class, 'import'])->middleware('throttle:10,1')->name('imports.store');
        Route::get('/imports/template', [MarketController::class, 'template'])->name('imports.template');

        Route::get('/market', [PublicMarketController::class, 'index'])->withoutMiddleware('verified')->name('market');
        Route::post('/market/scan', [PublicMarketController::class, 'scan'])->middleware('throttle:ai-assistant')->name('market.scan');

        Route::post('/ai/chat', [AiAssistantController::class, 'store'])->middleware('throttle:ai-assistant')->name('ai.chat');
        Route::delete('/ai/chat', [AiAssistantController::class, 'destroy'])->name('ai.chat.clear');

        // Phase 5: notifications and alert rules.
        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications');
        Route::get('/notifications/rules', [NotificationController::class, 'rules'])->name('notification-rules');
        Route::post('/notifications/rules', [NotificationController::class, 'storeRule'])->name('notification-rules.store');
        Route::post('/notifications/rules/{rule}', [NotificationController::class, 'updateRule'])->name('notification-rules.update');
        Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
        Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');

        // Phase 6: analytics and exports.
        Route::get('/analytics', [AnalyticsController::class, 'dashboard'])->name('analytics');
        Route::get('/analytics/export', [AnalyticsController::class, 'export'])->name('analytics.export');
    });
});

Route::middleware(['auth', EnsureActiveAccount::class, 'verified', EnsurePlatformAdmin::class])->group(function (): void {
    Route::get('/admin', [AdminController::class, 'index'])->name('admin');
    Route::post('/admin/organizations', [AdminController::class, 'storeOrganization'])->middleware('throttle:10,1')->name('admin.organizations.store');
    Route::post('/admin/organizations/{organization}', [AdminController::class, 'updateOrganization'])->middleware('throttle:20,1')->name('admin.organizations.update');
    Route::post('/admin/users/{user}', [AdminController::class, 'update'])->middleware('throttle:20,1')->name('admin.users.update');
});
