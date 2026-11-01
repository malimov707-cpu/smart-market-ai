<?php

use App\Models\AppNotification;
use App\Models\ImportBatch;
use App\Models\Offer;
use App\Services\ManageAlerts;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('denies direct tenant import evidence report rule and notification access', function () {
    [$user,$org] = marketIdentity();
    [$other,$foreign] = marketIdentity();
    $batch = ImportBatch::factory()->create(['organization_id' => $foreign->id, 'user_id' => $other->id]);
    $rule = app(ManageAlerts::class)->create($other, $foreign, ['name' => 'TEST private rule', 'event_type' => 'new_offer', 'scope_type' => 'global', 'threshold_kind' => 'none', 'frequency' => 'instant']);
    app(ManageAlerts::class)->dispatch($foreign, 'new_offer', 'TEST', 'TEST private');
    $notification = AppNotification::sole();
    $this->actingAs($user)->withSession(['organization_id' => $org->id]);
    foreach (['/import-center/'.$batch->id, '/import-center/'.$batch->id.'/file', '/import-center/'.$batch->id.'/errors'] as $path) {
        $this->get($path)->assertNotFound();
    }
    $this->post('/notifications/rules/'.$rule->id, ['enabled' => false])->assertNotFound();
    $this->post('/notifications/'.$notification->id.'/read')->assertNotFound();
    $this->get('/analytics/export')->assertOk();
    $this->get('/analytics')->assertInertia(fn ($page) => $page->where('stats.offers', 0));
});

it('keeps analytics readable but alert modification and import confirmation restricted for auditors', function () {
    [$user,$org] = marketIdentity('auditor');
    $batch = ImportBatch::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);
    $this->actingAs($user)->get('/analytics')->assertOk();
    $this->get('/notifications/rules')->assertForbidden();
    $this->post('/notifications/rules', [])->assertForbidden();
    $this->post('/import-center/'.$batch->id.'/cancel')->assertForbidden();
    $this->get('/import-center/'.$batch->id)->assertOk();
});

it('neutralizes whitespace prefixed spreadsheet formulas in report exports and applies verification filters', function () {
    [$user,$org] = marketIdentity();
    $offer = Offer::factory()->create(['organization_id' => $org->id, 'source_reference' => '  =HYPERLINK("TEST")']);
    $response = $this->actingAs($user)->get('/analytics/export');
    $response->assertOk();
    $text = $response->streamedContent();
    $stream = fopen('php://temp', 'w+b');
    fwrite($stream, $text);
    rewind($stream);
    fgetcsv($stream, 0, ',', '"', '');
    $row = fgetcsv($stream, 0, ',', '"', '');
    fclose($stream);
    expect($row[8])->toBe("'  =HYPERLINK(\"TEST\")");
    $filtered = $this->get('/analytics/export?verification=verified');
    $filtered->assertOk();
    expect($filtered->streamedContent())->not->toContain('HYPERLINK');
});
