<?php

use App\Models\AppNotification;
use App\Models\Organization;
use App\Models\User;
use App\Services\ManageAlerts;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

uses(LazilyRefreshDatabase::class);

it('resolves only explicit active subscribers and deduplicates personal global scope recipients', function () {
    [$owner,$org] = marketIdentity();
    $other = User::factory()->create();
    $org->users()->attach($other, ['role' => 'manager']);
    $outsider = User::factory()->create();
    $org->users()->attach($outsider, ['role' => 'auditor']);
    $service = app(ManageAlerts::class);
    $input = ['name' => 'TEST personal subscription', 'event_type' => 'new_offer', 'scope_type' => 'global', 'threshold_kind' => 'none', 'frequency' => 'instant', 'channels' => ['email']];
    $service->create($owner, $org, $input);
    $service->create($owner, $org, $input);
    $service->create($other, $org, $input);
    config(['mail.default' => 'log']);
    Mail::fake();
    expect($service->dispatch($org, 'new_offer', 'TEST event', 'TEST title'))->toBe(2);
    expect(AppNotification::pluck('user_id')->sort()->values()->all())->toBe(collect([$owner->id, $other->id])->sort()->values()->all());
    expect(AppNotification::where('email_status', 'unavailable')->count())->toBe(2);
    expect($service->dispatch($org, 'new_offer', 'TEST event', 'TEST title'))->toBe(0);
    Mail::assertNothingSent();
});

it('excludes revoked and deactivated subscribers and enforces owner only preference edits', function () {
    [$owner,$org] = marketIdentity();
    $service = app(ManageAlerts::class);
    $input = ['name' => 'TEST', 'event_type' => 'new_offer', 'scope_type' => 'global', 'threshold_kind' => 'none', 'frequency' => 'instant', 'channels' => ['app']];
    $rule = $service->create($owner, $org, $input);
    $other = User::factory()->create();
    $org->users()->attach($other, ['role' => 'owner']);
    $this->actingAs($other)->post('/notifications/rules/'.$rule->id, ['enabled' => false])->assertNotFound();
    $owner->forceFill(['is_active' => false])->save();
    expect($service->dispatch($org, 'new_offer', 'TEST1', 'TEST'))->toBe(0);
    $owner->forceFill(['is_active' => true])->save();
    $org->users()->detach($owner);
    expect($service->dispatch($org, 'new_offer', 'TEST2', 'TEST'))->toBe(0);
});

it('keeps in app notification when SMTP fails and never sends opted out subscriptions', function () {
    [$owner,$org] = marketIdentity();
    $service = app(ManageAlerts::class);
    $input = ['name' => 'TEST', 'event_type' => 'new_offer', 'scope_type' => 'global', 'threshold_kind' => 'none', 'frequency' => 'instant', 'channels' => ['app']];
    $rule = $service->create($owner, $org, $input);
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'TEST.invalid']);
    Mail::shouldReceive('mailer')->once()->with('smtp')->andThrow(new RuntimeException('TEST transport failure'));
    $service->dispatch($org, 'new_offer', 'TEST opted out', 'TEST');
    expect(AppNotification::sole()->email_status)->toBe('not_requested');
    $service->update($owner, $rule, ['channels' => ['email']]);
    $service->dispatch($org, 'new_offer', 'TEST failure', 'TEST');
    expect(AppNotification::where('email_status', 'failed')->count())->toBe(1);
    expect(AppNotification::count())->toBe(2);
});

it('records SMTP acceptance per subscribed recipient without claiming delivery or leaking recipients', function () {
    [$owner,$org] = marketIdentity();
    $other = User::factory()->create();
    $org->users()->attach($other, ['role' => 'owner']);
    $service = app(ManageAlerts::class);
    foreach ([$owner, $other] as $actor) {
        $service->create($actor, $org, ['name' => 'TEST SMTP subscription', 'event_type' => 'new_offer', 'scope_type' => 'global', 'threshold_kind' => 'none', 'frequency' => 'instant', 'channels' => ['email']]);
    }
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp', 'mail.mailers.smtp.host' => 'TEST.invalid']);
    $recipients = [];
    $mailer = Mockery::mock();
    $mailer->shouldReceive('raw')->twice()->andReturnUsing(function ($body, $callback) use (&$recipients) {
        $message = new Message(new Email);
        $callback($message);
        $recipients[] = array_map(fn ($address) => $address->getAddress(), $message->getSymfonyMessage()->getTo());

        return new stdClass;
    });
    Mail::shouldReceive('mailer')->twice()->with('smtp')->andReturn($mailer);
    $service->dispatch($org, 'new_offer', 'SMTP TEST', 'TEST title');
    expect($recipients)->toBe([[$owner->email], [$other->email]]);
    expect(AppNotification::where('email_status', 'sent')->count())->toBe(2);
});

it('keeps notification deduplication and recipient resolution isolated across organizations', function () {
    [$owner,$a] = marketIdentity();
    $b = Organization::factory()->create();
    $b->users()->attach($owner, ['role' => 'owner']);
    $service = app(ManageAlerts::class);
    foreach ([$a, $b] as $org) {
        $service->create($owner, $org, ['name' => 'TEST same event', 'event_type' => 'new_offer', 'scope_type' => 'global', 'threshold_kind' => 'none', 'frequency' => 'instant', 'channels' => ['app']]);
        $service->dispatch($org, 'new_offer', 'same-id', 'TEST title');
    }
    expect(AppNotification::count())->toBe(2);
    $notification = AppNotification::where('organization_id', $a->id)->sole();
    $this->actingAs($owner)->withSession(['organization_id' => $b->id])->post('/notifications/'.$notification->id.'/read')->assertNotFound();
});
