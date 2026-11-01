<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AlertRule;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Services\ManageAlerts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->attributes->get('organization');
        $unreadOnly = $request->boolean('unread');
        $notifications = AppNotification::where('organization_id', $organization->id)->where('user_id', $request->user()->id)
            ->when($unreadOnly, fn ($query) => $query->unread())
            ->orderByDesc('id')->paginate(20)->withQueryString()->through(fn (AppNotification $n): array => [
                'id' => $n->id, 'event_type' => $n->event_type, 'title' => $n->title, 'body' => $n->body,
                'url' => $n->url, 'read_at' => $n->read_at?->toISOString(), 'email_status' => $n->email_status, 'created_at' => $n->created_at->toISOString(),
            ]);

        return Inertia::render('Notifications', [
            'notifications' => $notifications,
            'unreadCount' => $this->unreadCount($request->user()->id, $organization->id),
            'filter' => $unreadOnly ? 'unread' : 'all',
        ]);
    }

    public function rules(Request $request, ManageAlerts $alerts): Response
    {
        $organization = $request->attributes->get('organization');
        Gate::authorize('manage', $organization);

        return Inertia::render('AlertRules', [
            'rules' => collect($alerts->rules($request->user(), $organization))->map(fn ($rule): array => $this->shape($rule))->all(),
            'eventTypes' => ManageAlerts::EVENTS,
            'canManage' => true,
        ]);
    }

    public function storeRule(Request $request, ManageAlerts $alerts): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $alerts->create($request->user(), $organization, $request->all());

        return back()->with('success', 'Ogohlantirish qoidasi yaratildi.');
    }

    public function updateRule(Request $request, ManageAlerts $alerts, int $rule): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        $existing = AlertRule::where('organization_id', $organization->id)->findOrFail($rule);
        $alerts->update($request->user(), $existing, $request->all());

        return back()->with('success', 'Qoida yangilandi.');
    }

    public function read(Request $request, int $notification): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        AppNotification::where('organization_id', $organization->id)->where('user_id', $request->user()->id)->findOrFail($notification)
            ->forceFill(['read_at' => now()])->save();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $organization = $request->attributes->get('organization');
        AppNotification::where('organization_id', $organization->id)->where('user_id', $request->user()->id)->unread()
            ->update(['read_at' => now()]);
        AuditLog::create(['organization_id' => $organization->id, 'user_id' => $request->user()->id, 'action' => 'notifications.read_all', 'subject_type' => 'user', 'subject_id' => $request->user()->id]);

        return back();
    }

    private function unreadCount(int $userId, int $organizationId): int
    {
        return AppNotification::where('organization_id', $organizationId)->where('user_id', $userId)->unread()->count();
    }

    private function shape(AlertRule $rule): array
    {
        return ['id' => $rule->id, 'name' => $rule->name, 'event_type' => $rule->event_type, 'scope_type' => $rule->scope_type,
            'scope_id' => $rule->scope_id, 'threshold_kind' => $rule->threshold_kind, 'threshold_value' => $rule->threshold_value,
            'frequency' => $rule->frequency, 'channels' => $rule->channels ?? ['app'], 'enabled' => $rule->enabled];
    }
}
