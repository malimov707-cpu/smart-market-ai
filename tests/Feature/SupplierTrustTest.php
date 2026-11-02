<?php

use App\Models\CatalogFile;
use App\Models\Offer;
use App\Models\Supplier;
use App\Models\SupplierDispute;
use App\Models\SupplierReview;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(LazilyRefreshDatabase::class);
function supplierEvidence(User $user, Supplier $supplier): CatalogFile
{
    test()->actingAs($user)->post('/suppliers/'.$supplier->id.'/documents', ['file' => UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\nTEST evidence document")])->assertSessionHasNoErrors();

    return CatalogFile::where('supplier_id', $supplier->id)->latest('id')->first();
}
it('requires independent documented organization approval and preserves evidence', function () {
    Storage::fake('catalog');
    [$owner,$org] = marketIdentity();
    $manager = User::factory()->create(['is_platform_admin' => true]);
    $org->users()->attach($manager, ['role' => 'manager']);
    $supplier = Supplier::factory()->create(['organization_id' => $org->id]);
    $file = supplierEvidence($manager, $supplier);
    $this->post('/suppliers/'.$supplier->id.'/verification', ['scope' => 'organization', 'action' => 'submit', 'reason' => 'Documents supplied for actual review', 'evidence_ids' => [(string) $file->id]])->assertSessionHasNoErrors();
    expect($supplier->fresh()->verification_status)->toBe('pending');
    $this->post('/suppliers/'.$supplier->id.'/verification', ['scope' => 'organization', 'action' => 'approve', 'reason' => 'Checked actual registration evidence'])->assertSessionHasErrors('action');
    $this->actingAs($owner)->post('/suppliers/'.$supplier->id.'/verification', ['scope' => 'organization', 'action' => 'approve', 'reason' => 'Checked actual registration evidence'])->assertSessionHasNoErrors();
    expect($supplier->fresh()->verification_status)->toBe('verified');
    expect($supplier->fresh()->platform_verification_status)->toBe('unverified');
    $this->post('/catalog/files/'.$file->id.'/retire')->assertSessionHasErrors('file');
    $this->post('/suppliers/'.$supplier->id.'/verification', ['scope' => 'organization', 'action' => 'suspend', 'reason' => 'Registration evidence has become invalid'])->assertSessionHasNoErrors();
    expect($supplier->fresh()->verification_status)->toBe('suspended');
    $this->assertDatabaseHas('supplier_verifications', ['actor_id' => $owner->id, 'to_status' => 'verified']);
});
it('rejects self approval and organization owners cannot grant platform verification', function () {
    Storage::fake('catalog');
    [$owner,$org] = marketIdentity();
    $supplier = Supplier::factory()->create(['organization_id' => $org->id]);
    $file = supplierEvidence($owner, $supplier);
    $this->post('/suppliers/'.$supplier->id.'/verification', ['scope' => 'organization', 'action' => 'submit', 'reason' => 'Actual registration supplied', 'evidence_ids' => [$file->id]])->assertSessionHasNoErrors();
    $this->post('/suppliers/'.$supplier->id.'/verification', ['scope' => 'organization', 'action' => 'approve', 'reason' => 'Attempted self approval'])->assertSessionHasErrors('action');
    $this->post('/suppliers/'.$supplier->id.'/verification', ['scope' => 'platform', 'action' => 'approve', 'reason' => 'Attempted global verification'])->assertForbidden();
});
it('supports rejection and separately permissioned platform decisions', function () {
    Storage::fake('catalog');
    [$owner,$org] = marketIdentity();
    $reviewer = User::factory()->create(['is_platform_admin' => true]);
    $org->users()->attach($reviewer, ['role' => 'owner']);
    DB::table('platform_permissions')->insert(['user_id' => $reviewer->id, 'permission' => 'supplier.verify']);
    $supplier = Supplier::factory()->create(['organization_id' => $org->id]);
    $file = supplierEvidence($owner, $supplier);
    $input = ['scope' => 'organization', 'action' => 'submit', 'reason' => 'Please review actual evidence', 'evidence_ids' => [$file->id]];
    $this->post('/suppliers/'.$supplier->id.'/verification', $input)->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->withSession(['organization_id' => $org->id])->post('/suppliers/'.$supplier->id.'/verification', ['scope' => 'organization', 'action' => 'reject', 'reason' => 'Evidence does not identify the legal entity'])->assertSessionHasNoErrors();
    expect($supplier->fresh()->verification_status)->toBe('rejected');
    $this->actingAs($owner)->post('/suppliers/'.$supplier->id.'/verification', [...$input, 'scope' => 'platform'])->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->post('/suppliers/'.$supplier->id.'/verification', ['scope' => 'platform', 'action' => 'approve', 'reason' => 'Independently checked registry and evidence'])->assertSessionHasNoErrors();
    expect($supplier->fresh()->platform_verification_status)->toBe('verified');
});
it('refuses missing or foreign verification evidence and tenant ids', function () {
    [$owner,$org] = marketIdentity();
    $supplier = Supplier::factory()->create(['organization_id' => $org->id]);
    $foreign = Supplier::factory()->create();
    $this->actingAs($owner)->post('/suppliers/'.$supplier->id.'/verification', ['scope' => 'organization', 'action' => 'submit', 'reason' => 'Missing evidence should fail', 'evidence_ids' => [99999]])->assertSessionHasErrors('evidence_ids');
    $this->post('/suppliers/'.$foreign->id.'/verification', ['scope' => 'organization', 'action' => 'submit', 'reason' => 'Cross tenant attempt'])->assertSessionHasErrors('evidence_ids');
});
it('requires real offer evidence for review and prevents duplicate feedback', function () {
    [$buyer,$org] = marketIdentity('buyer');
    $offer = Offer::factory()->create(['organization_id' => $org->id]);
    $input = ['offer_id' => $offer->id, 'rating' => 4, 'body' => 'Offer terms were clearly described and current.'];
    $this->actingAs($buyer)->post('/suppliers/'.$offer->supplier_id.'/reviews', [...$input, 'offer_id' => 99999])->assertSessionHasErrors('offer_id');
    $this->post('/suppliers/'.$offer->supplier_id.'/reviews', $input)->assertSessionHasNoErrors();
    $this->post('/suppliers/'.$offer->supplier_id.'/reviews', $input)->assertSessionHasErrors('body');
    $this->assertDatabaseCount('supplier_reviews', 1);
});
it('opens and independently resolves a dispute with moderation audit', function () {
    [$buyer,$org] = marketIdentity('buyer');
    $owner = User::factory()->create(['is_platform_admin' => true]);
    $org->users()->attach($owner, ['role' => 'owner']);
    $offer = Offer::factory()->create(['organization_id' => $org->id]);
    $this->actingAs($buyer)->post('/suppliers/'.$offer->supplier_id.'/reviews', ['offer_id' => $offer->id, 'rating' => 2, 'body' => 'Actual offer terms need a correction after review.'])->assertSessionHasNoErrors();
    $review = SupplierReview::sole();
    $this->post('/suppliers/'.$offer->supplier_id.'/reviews/'.$review->id.'/dispute', ['reason' => 'The original feedback needs evidence-based correction.'])->assertSessionHasNoErrors();
    $dispute = SupplierDispute::sole();
    $this->post('/suppliers/'.$offer->supplier_id.'/disputes/'.$dispute->id.'/resolve', ['resolution' => 'I cannot moderate my own feedback or dispute.', 'hide_review' => 1])->assertForbidden();
    $this->actingAs($owner)->withSession(['organization_id' => $org->id])->post('/suppliers/'.$offer->supplier_id.'/disputes/'.$dispute->id.'/resolve', ['resolution' => 'Evidence supports withholding this review from the aggregate.', 'hide_review' => 1])->assertSessionHasNoErrors();
    expect($dispute->fresh()->resolved_at)->not->toBeNull();
    expect($review->fresh()->hidden_at)->not->toBeNull();
    $this->assertDatabaseHas('audit_logs', ['action' => 'supplier.dispute_resolved']);
});
