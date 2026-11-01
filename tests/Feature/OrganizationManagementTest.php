<?php

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\OrganizationInvitation;
use App\Services\ManageMemberships;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

uses(LazilyRefreshDatabase::class);

it('defines permissions for all five roles', function (string $role, bool $catalog, bool $offers, bool $admin) {
    [$user, $organization] = marketIdentity($role);
    expect($user->can('view', $organization))->toBeTrue();
    expect($user->can('catalog', $organization))->toBe($catalog);
    expect($user->can('manage', $organization))->toBe($offers);
    expect($user->can('administer', $organization))->toBe($admin);
})->with([
    ['owner', true, true, true], ['manager', true, true, false], ['buyer', false, true, false],
    ['analyst', false, false, false], ['auditor', false, false, false],
]);

it('updates organization details with an audit record', function () {
    [$user, $organization] = marketIdentity();
    $this->actingAs($user)->post('/settings', ['name' => 'Updated Org', 'city' => 'Toshkent', 'id' => 999])->assertSessionHasNoErrors();
    expect($organization->fresh()->name)->toBe('Updated Org');
    $this->assertDatabaseHas('audit_logs', ['action' => 'organization.updated', 'organization_id' => $organization->id]);
});

it('denies membership administration to non-owner roles', function (string $role) {
    [$user] = marketIdentity($role);
    $this->actingAs($user)->post('/settings', ['name' => 'Denied', 'city' => 'Toshkent'])->assertForbidden();
    $this->post('/settings/invitations', ['email' => 'new@example.test', 'role' => 'owner'])->assertForbidden();
    $this->post('/settings/members/'.$user->id.'/role', ['role' => 'owner'])->assertForbidden();
    $this->get('/settings')->assertInertia(fn (Assert $page) => $page->where('canAdminister', false)->where('members', null)->where('invitations', null));
})->with(['manager', 'buyer', 'analyst', 'auditor']);

it('allows buyers to record offers but denies catalog changes', function () {
    [$user, $organization] = marketIdentity('buyer');
    $input = offerInput($organization);
    $this->actingAs($user)->post('/offers', $input)->assertSessionHasNoErrors();
    $this->post('/products', ['name' => 'Denied', 'sku' => 'D', 'unit' => 'kg'])->assertForbidden();
    $this->assertDatabaseCount('offers', 1);
});

it('sends an invitation while storing only a token hash', function () {
    Notification::fake();
    [$user, $organization] = marketIdentity();
    $this->actingAs($user)->post('/settings/invitations', ['email' => 'invitee@example.test', 'role' => 'buyer'])->assertSessionHasNoErrors();
    $invitation = Invitation::sole();
    expect($invitation->token_hash)->toHaveLength(64);
    expect($invitation->toArray())->not->toHaveKey('token_hash');
    Notification::assertSentOnDemand(OrganizationInvitation::class);
    $this->assertDatabaseHas('audit_logs', ['action' => 'invitation.created', 'organization_id' => $organization->id]);
    $this->post('/settings/invitations', ['email' => 'invitee@example.test', 'role' => 'owner'])->assertSessionHasErrors('email');
});

it('accepts an invitation once for the verified recipient and switches organizations', function () {
    $this->freezeTime();
    [$owner, $organization] = marketIdentity();
    [$recipient] = marketIdentity('auditor');
    [$invitation, $token] = app(ManageMemberships::class)->invite($owner, $organization, $recipient->email, 'buyer');
    $this->actingAs($recipient)->get('/invitations/'.$token)->assertInertia(fn (Assert $page) => $page->component('Invitation'));
    $this->post('/invitations/'.$token.'/accept')->assertRedirect('/dashboard');
    $this->assertDatabaseHas('organization_user', ['organization_id' => $organization->id, 'user_id' => $recipient->id, 'role' => 'buyer']);
    expect($invitation->fresh()->accepted_at)->not->toBeNull();
    $this->post('/invitations/'.$token.'/accept')->assertGone();
    $this->assertDatabaseHas('audit_logs', ['action' => 'invitation.accepted', 'user_id' => $recipient->id]);
});

it('refuses a recipient mismatch and an unverified recipient', function () {
    [$owner, $organization] = marketIdentity();
    $recipient = User::factory()->unverified()->create();
    [, $token] = app(ManageMemberships::class)->invite($owner, $organization, $recipient->email, 'manager');
    $this->actingAs($owner)->get('/invitations/'.$token)->assertForbidden();
    $this->post('/invitations/'.$token.'/accept')->assertForbidden();
    $this->actingAs($recipient)->post('/invitations/'.$token.'/accept')->assertRedirect('/verify-email');
    $this->assertDatabaseMissing('organization_user', ['organization_id' => $organization->id, 'user_id' => $recipient->id]);
});

it('refuses expired revoked and stale-authority invitations', function (string $condition) {
    $this->freezeTime();
    [$owner, $organization] = marketIdentity();
    $recipient = User::factory()->create(['is_platform_admin' => true]);
    [$invitation, $token] = app(ManageMemberships::class)->invite($owner, $organization, $recipient->email, 'analyst');
    match ($condition) {
        'expired' => $this->travel(8)->days(),
        'revoked' => app(ManageMemberships::class)->revoke($owner, $organization, $invitation->id),
        'authority' => $organization->users()->detach($owner),
    };
    $this->actingAs($recipient)->post('/invitations/'.$token.'/accept')->assertGone();
    $this->assertDatabaseMissing('organization_user', ['organization_id' => $organization->id, 'user_id' => $recipient->id]);
})->with(['expired', 'revoked', 'authority']);

it('cannot revoke another tenant invitation or change a foreign member', function () {
    [$owner, $organization] = marketIdentity();
    [$other, $foreign] = marketIdentity();
    [$invitation] = app(ManageMemberships::class)->invite($other, $foreign, 'another@example.test', 'auditor');
    $this->actingAs($owner)->post('/settings/invitations/'.$invitation->id.'/revoke')->assertNotFound();
    $this->post('/settings/members/'.$other->id.'/role', ['role' => 'buyer'])->assertNotFound();
    $this->post('/settings/members/'.$other->id.'/remove')->assertNotFound();
});

it('refuses removing demoting or deactivating the last active owner', function () {
    [$owner, $organization] = marketIdentity();
    $inactive = User::factory()->create(['is_active' => false]);
    $organization->users()->attach($inactive, ['role' => 'owner']);
    $this->actingAs($owner)->post('/settings/members/'.$owner->id.'/role', ['role' => 'manager'])->assertSessionHasErrors('membership');
    $this->post('/settings/members/'.$owner->id.'/remove')->assertSessionHasErrors('membership');
    $this->assertDatabaseHas('organization_user', ['organization_id' => $organization->id, 'user_id' => $owner->id, 'role' => 'owner']);
});

it('changes roles and removes members while preserving an active owner', function () {
    [$owner, $organization] = marketIdentity();
    $member = User::factory()->create(['is_platform_admin' => true]);
    $organization->users()->attach($member, ['role' => 'auditor']);
    $this->actingAs($owner)->post('/settings/members/'.$member->id.'/role', ['role' => 'owner'])->assertSessionHasNoErrors();
    $this->post('/settings/members/'.$owner->id.'/role', ['role' => 'analyst'])->assertSessionHasNoErrors();
    $this->actingAs($member)->withSession(['organization_id' => $organization->id])->post('/settings/members/'.$owner->id.'/remove')->assertSessionHasNoErrors();
    $this->assertDatabaseMissing('organization_user', ['organization_id' => $organization->id, 'user_id' => $owner->id]);
    $this->actingAs($owner)->withSession(['organization_id' => $organization->id])->get('/dashboard')->assertForbidden();
});

it('rejects invitations when mail is unconfigured without saving a live token', function () {
    config(['mail.default' => 'log']);
    [$owner] = marketIdentity();
    $this->actingAs($owner)->post('/settings/invitations', ['email' => 'recipient@example.test', 'role' => 'auditor'])->assertSessionHasErrors('email');
    $this->assertDatabaseCount('invitations', 0);
});

it('revokes an invitation when its mail transport fails and exposes no transport secrets', function () {
    [$owner] = marketIdentity();
    Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('SECRET-TRANSPORT-CONTENT'));
    $this->actingAs($owner)->post('/settings/invitations', ['email' => 'recipient@example.test', 'role' => 'auditor'])->assertSessionHasErrors('email');
    $invitation = Invitation::sole();
    expect($invitation->revoked_at)->not->toBeNull();
    expect($invitation->pending_email)->toBeNull();
    $this->assertDatabaseHas('security_events', ['action' => 'invitation.mail_failed']);
});
