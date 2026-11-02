<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AlertRule;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Phase 5: tenant-scoped alert evaluation and in-app notification creation.
 * Dedupe keys prevent repeated notifications for the same underlying event.
 * Email is attempted only when mail is configured; honest status is recorded.
 */
class ManageAlerts
{
    public const EVENTS = ['price_decrease', 'price_increase', 'new_offer', 'lowest_price_changed', 'price_verification_approved', 'price_verification_rejected', 'price_dispute', 'supplier_verification_changed', 'import_completed', 'import_completed_with_errors', 'import_failed'];

    public function rules(User $actor, Organization $organization, array $input = []): array
    {
        Validator::make($input, ['event_type' => ['nullable', Rule::in(self::EVENTS)], 'enabled' => ['nullable', 'boolean']])->validate();

        Gate::forUser($actor)->authorize('manage', $organization);

        return AlertRule::whereIn('organization_id', Organization::allIds())->where('user_id', $actor->id)
            ->when($input['event_type'] ?? null, fn ($query, $type) => $query->where('event_type', $type))
            ->when(isset($input['enabled']), fn ($query) => $query->where('enabled', (bool) $input['enabled']))
            ->orderBy('id')->get()->all();
    }

    public function create(User $actor, Organization $organization, array $input): AlertRule
    {
        Gate::forUser($actor)->authorize('manage', $organization);
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'event_type' => ['required', Rule::in(self::EVENTS)],
            'scope_type' => ['required', Rule::in(['product', 'supplier', 'global'])],
            'scope_id' => ['nullable', 'integer'],
            'threshold_kind' => ['required', Rule::in(['none', 'percent', 'absolute'])],
            'threshold_value' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,4})?$/', 'required_if:threshold_kind,percent,absolute'],
            'frequency' => ['required', Rule::in(['instant'])],
            'channels' => ['sometimes', 'array'], 'channels.*' => ['in:app,email'],
        ])->validate();
        if ($data['scope_type'] !== 'global') {
            $data['scope_id'] = (int) ($data['scope_id'] ?? 0);
            $exists = $data['scope_type'] === 'product'
                ? DB::table('products')->whereIn('organization_id', Organization::allIds())->where('id', $data['scope_id'])->exists()
                : ($data['scope_type'] === 'supplier'
                    ? DB::table('suppliers')->whereIn('organization_id', Organization::allIds())->where('id', $data['scope_id'])->exists()
                    : DB::table('products')->whereIn('organization_id', Organization::allIds())->where('category', $data['scope_id'] ?? '')->exists());
            abort_unless($exists || ($data['scope_type'] === 'category' && is_string($input['scope_id'] ?? null)), 422, 'Scope mos tashkilot yozuviga tegishli emas.');
        } else {
            $data['scope_id'] = null;
        }
        $rule = AlertRule::create([...$data, 'organization_id' => $organization->id, 'user_id' => $actor->id,
            'channels' => $data['channels'] ?? ['app'], 'threshold_value' => $data['threshold_value'] ?? null]);
        AuditLog::create(['organization_id' => $organization->id, 'user_id' => $actor->id, 'action' => 'alert_rule.created',
            'subject_type' => 'alert_rule', 'subject_id' => $rule->id, 'metadata' => ['event_type' => $rule->event_type]]);

        return $rule;
    }

    public function update(User $actor, AlertRule $rule, array $input): AlertRule
    {
        Gate::forUser($actor)->authorize('manage', Organization::findOrFail($rule->organization_id));
        abort_unless($rule->user_id === $actor->id, 404);
        $data = Validator::make($input, ['enabled' => ['sometimes', 'boolean'], 'threshold_value' => ['sometimes', 'regex:/^\d{1,10}(?:\.\d{1,4})?$/'],
            'frequency' => ['sometimes', Rule::in(['instant'])], 'name' => ['sometimes', 'string', 'max:120'],
            'channels' => ['sometimes', 'array'], 'channels.*' => ['in:app,email']])->validate();
        $rule->update($data);
        AuditLog::create(['organization_id' => $rule->organization_id, 'user_id' => $actor->id, 'action' => 'alert_rule.updated',
            'subject_type' => 'alert_rule', 'subject_id' => $rule->id, 'metadata' => $data]);

        return $rule;
    }

    /** Notify every enabled matching rule owner. Idempotent via user+dedupe_key. */
    public function dispatch(Organization $organization, string $eventType, string $dedupeKey, string $title, ?string $body = null, array $metadata = [], ?string $url = null): int
    {
        $rules = AlertRule::whereIn('organization_id', Organization::allIds())->where('enabled', true)->where('event_type', $eventType)->where('frequency', 'instant')->with('user')->orderBy('id')->get();
        $recipients = [];
        foreach ($rules as $rule) {
            $user = $rule->user;
            if ($user && $user->is_active && $user->hasVerifiedEmail() && $user->can('view', $organization)) {
                $recipients[$user->id] = $user;
            }
        }
        $emailRecipients = [];
        $createdNotifications = [];
        $created = 0;
        foreach ($rules as $rule) {
            if (! isset($recipients[$rule->user_id])) {
                continue;
            }
            $scopeId = $metadata['scope_id'] ?? null;
            $category = $metadata['category'] ?? null;
            if ($rule->scope_type === 'product' && (int) $scopeId !== (int) $rule->scope_id) {
                continue;
            }
            if ($rule->scope_type === 'supplier' && (int) ($metadata['supplier_id'] ?? null) !== (int) $rule->scope_id) {
                continue;
            }
            if ($rule->scope_type === 'category' && $category !== (string) $rule->scope_id) {
                continue;
            }
            $threshold = $rule->threshold_kind === 'none' ? null : $rule->threshold_value;
            $change = $metadata['change_value'] ?? null;
            if ($threshold !== null && ($change === null || bccomp(ltrim((string) $change, '-'), (string) $threshold, 4) < 0)) {
                continue;
            }
            $key = hash('sha256', $organization->id.'|'.$eventType.'|'.$dedupeKey);
            $notification = AppNotification::firstOrCreate(['user_id' => $rule->user_id, 'dedupe_key' => $key],
                ['organization_id' => $organization->id, 'alert_rule_id' => $rule->id, 'event_type' => $eventType, 'title' => $title,
                    'body' => $body, 'metadata' => $metadata, 'url' => $url, 'email_status' => 'not_requested']);
            if ($notification->wasRecentlyCreated) {
                $created++;
                $createdNotifications[$rule->user_id] = $notification;
            }
            if (in_array('email', $rule->channels ?? [], true)) {
                $emailRecipients[$rule->user_id] = true;
            }
        }
        foreach ($createdNotifications as $userId => $notification) {
            if (isset($emailRecipients[$userId])) {
                $this->attemptEmail($recipients[$userId], $notification);
            }
        }

        return $created;
    }

    /** sent means SMTP transport accepted, never delivered. Local log/array transports are unavailable. */
    private function attemptEmail(User $recipient, AppNotification $notification): void
    {
        $mailer = config('mail.default');
        $transport = config('mail.mailers.'.$mailer.'.transport');
        if ($transport !== 'smtp' || ! config('mail.from.address') || ! config('mail.mailers.'.$mailer.'.host')) {
            $notification->update(['email_status' => 'unavailable']);

            return;
        }
        try {
            $message = Mail::mailer($mailer)->raw($notification->body ?? $notification->title,
                fn ($mail) => $mail->to($recipient->email)->subject('[Smart Market] '.$notification->title));
            $notification->update(['email_status' => $message === null ? 'failed' : 'sent']);
        } catch (\Throwable) {
            $notification->update(['email_status' => 'failed']);
        }
    }
}
