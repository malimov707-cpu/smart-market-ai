<?php

use App\Models\AlertRule;
use App\Models\AppNotification;
use App\Models\Offer;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\ManageAlerts;
use App\Services\ManageImports;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(LazilyRefreshDatabase::class);

it('creates a tenant-scoped alert rule with audit and rejects foreign scope', function () {
    [$user, $org] = marketIdentity('owner');
    $product = Product::factory()->create(['organization_id' => $org->id]);
    app(ManageAlerts::class)->create($user, $org, ['name' => 'Pasayish', 'event_type' => 'price_decrease', 'scope_type' => 'product',
        'scope_id' => $product->id, 'threshold_kind' => 'percent', 'threshold_value' => '5', 'frequency' => 'instant']);
    expect(AlertRule::count())->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'alert_rule.created']);
    [$otherUser, $otherOrg] = marketIdentity('owner');
    expect(fn () => app(ManageAlerts::class)->create($otherUser, $otherOrg, ['name' => 'X', 'event_type' => 'price_decrease',
        'scope_type' => 'product', 'scope_id' => $product->id, 'threshold_kind' => 'none', 'frequency' => 'instant']))
        ->toThrow(HttpException::class);
});

it('dispatches deduplicated notifications respecting scope and threshold', function () {
    [$user, $org] = marketIdentity('owner');
    $product = Product::factory()->create(['organization_id' => $org->id]);
    app(ManageAlerts::class)->create($user, $org, ['name' => 'Katta pasayish', 'event_type' => 'price_decrease', 'scope_type' => 'product',
        'scope_id' => $product->id, 'threshold_kind' => 'percent', 'threshold_value' => '5', 'frequency' => 'instant', 'channels' => ['app']]);
    $alerts = app(ManageAlerts::class);
    expect($alerts->dispatch($org, 'price_decrease', 'offer:1', 'Narx pasaydi', null, ['scope_id' => $product->id, 'change_value' => '-2.5']))->toBe(0);
    expect($alerts->dispatch($org, 'price_decrease', 'offer:1', 'Narx pasaydi', null, ['scope_id' => $product->id, 'change_value' => '-7']))->toBe(1);
    expect($alerts->dispatch($org, 'price_decrease', 'offer:1', 'Narx pasaydi', null, ['scope_id' => $product->id, 'change_value' => '-7']))->toBe(0);
    expect(AppNotification::where('user_id', $user->id)->count())->toBe(1);
    // other tenant user sees nothing
    [$u2, $o2] = marketIdentity('owner');
    expect(AppNotification::where('user_id', $u2->id)->count())->toBe(0);
});

it('marks email honestly unavailable without SMTP config', function () {
    config(['mail.default' => null, 'mail.from.address' => null]);
    Mail::fake();
    [$user, $org] = marketIdentity('owner');
    Product::factory()->create(['organization_id' => $org->id]);
    app(ManageAlerts::class)->create($user, $org, ['name' => 'R', 'event_type' => 'price_decrease', 'scope_type' => 'global',
        'threshold_kind' => 'none', 'frequency' => 'instant', 'channels' => ['app', 'email']]);
    app(ManageAlerts::class)->dispatch($org, 'price_decrease', 'offer:9', 'Narx pasaydi');
    expect(AppNotification::sole()->email_status)->toBe('unavailable');
    Mail::assertNothingSent();
});

it('notifies import completion and honors unread state', function () {
    config(['mail.default' => null, 'mail.from.address' => null]);
    [$user, $org] = marketIdentity('owner');
    Product::factory()->create(['organization_id' => $org->id]);
    app(ManageAlerts::class)->create($user, $org, ['name' => 'Import', 'event_type' => 'import_completed', 'scope_type' => 'global',
        'threshold_kind' => 'none', 'frequency' => 'instant']);
    [$batch] = ManageImports::class ? [null] : [];
    app(ManageAlerts::class)->dispatch($org, 'import_completed', 'batch:77:0', 'Import yakunlandi');
    $notification = AppNotification::sole();
    expect($notification->read_at)->toBeNull();
    $this->actingAs($user)->post("/notifications/{$notification->id}/read")->assertRedirect();
    expect($notification->fresh()->read_at)->not->toBeNull();
});

it('shows analytics dashboard from real data and exports csv with formula injection protection', function () {
    [$user, $org] = marketIdentity('owner');
    $product = Product::factory()->create(['organization_id' => $org->id, 'category' => 'Test-cat']);
    $supplier = Supplier::factory()->create(['organization_id' => $org->id]);
    Offer::factory()->create(['organization_id' => $org->id, 'product_id' => $product->id, 'supplier_id' => $supplier->id,
        'price' => '100.50', 'currency' => 'UZS', 'unit' => 'kg', 'package_quantity' => '1', 'minimum_packages' => 1,
        'observed_at' => now()->subDays(2), 'source_reference' => '=cmd|test']);
    $this->actingAs($user)->get('/analytics')->assertOk()->assertInertia(fn ($page) => $page->component('AnalyticsDashboard')
        ->where('stats.products', 1)->where('stats.offers', 1));
    $response = $this->actingAs($user)->get('/analytics/export');
    $response->assertOk();
    $body = $response->streamedContent();
    expect(str_contains($body, "\'=cmd|test"))->toBeTrue();
    // tenant isolation: other org user cannot read our analytics rows
    [$u2, $o2] = marketIdentity('owner');
    $this->actingAs($u2)->get('/analytics')->assertOk()->assertInertia(fn ($page) => $page->where('stats.offers', 0));
});
