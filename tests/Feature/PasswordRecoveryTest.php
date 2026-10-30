<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

uses(LazilyRefreshDatabase::class);

it('sends recovery links without revealing whether the email exists', function () {
    Notification::fake();
    $user = User::factory()->create();
    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('success');
    Notification::assertSentTo($user, ResetPassword::class);
    $this->post('/forgot-password', ['email' => 'missing@example.test'])->assertSessionHas('success');
});

it('resets a password with a valid token and revokes database sessions', function () {
    $user = User::factory()->create();
    DB::table('sessions')->insert(['id' => 'old-session', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);
    $token = Password::createToken($user);
    $this->post('/reset-password', ['email' => $user->email, 'token' => $token,
        'password' => 'NewPassword12345', 'password_confirmation' => 'NewPassword12345'])->assertRedirect('/login');
    expect(Hash::check('NewPassword12345', $user->fresh()->password))->toBeTrue();
    $this->assertDatabaseMissing('sessions', ['id' => 'old-session']);
    $this->post('/reset-password', ['email' => $user->email, 'token' => $token,
        'password' => 'OtherPassword12345', 'password_confirmation' => 'OtherPassword12345'])->assertSessionHasErrors('email');
});

it('rejects invalid and expired recovery tokens without changing the password', function () {
    $user = User::factory()->create();
    $password = $user->password;
    $token = Password::createToken($user);
    $this->travel(61)->minutes();
    $this->post('/reset-password', ['email' => $user->email, 'token' => $token,
        'password' => 'NewPassword12345', 'password_confirmation' => 'NewPassword12345'])->assertSessionHasErrors('email');
    expect($user->fresh()->password)->toBe($password);
});
