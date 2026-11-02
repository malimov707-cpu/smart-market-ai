<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ImportBatch;
use App\Models\Offer;
use App\Models\Product;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\AccountSecurity;
use App\Services\OpenRouterAssistant;
use App\Services\OperationsCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', 'active', 'suspended', 'unverified', 'admins'])],
            'tab' => ['nullable', Rule::in(['overview', 'users', 'security', 'operations'])]]);
        $search = $filters['q'] ?? '';
        $registrations = User::where('created_at', '>=', now()->startOfDay()->subDays(13))
            ->selectRaw('DATE(created_at) AS day, COUNT(*) AS total')->groupByRaw('DATE(created_at)')->pluck('total', 'day');

        return Inertia::render('Admin', [
            'filters' => ['q' => $search, 'status' => $filters['status'] ?? 'all', 'tab' => $filters['tab'] ?? 'overview'],
            'operations' => app(OperationsCheck::class)->dependencies(),
            'assistant' => app(OpenRouterAssistant::class)->availability(),
            'imports' => ImportBatch::query()->latest('id')->limit(8)->get(['id', 'file_name', 'status', 'row_count', 'error_count', 'created_at']),
            'growth' => collect(range(13, 0))->map(fn (int $days): array => [
                'day' => now()->subDays($days)->format('Y-m-d'),
                'users' => (int) ($registrations[now()->subDays($days)->format('Y-m-d')] ?? 0),
            ]),
            'stats' => [
                'users' => User::count(), 'products' => Product::count(), 'offers' => Offer::count(),
                'suspended' => User::where('is_suspended', true)->count(),
            ],
            'users' => User::query()->select(['id', 'name', 'email', 'is_active', 'is_suspended', 'is_platform_admin', 'email_verified_at', 'created_at'])
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')))
                ->when(($filters['status'] ?? 'all') === 'active', fn ($query) => $query->where('is_active', true)->where('is_suspended', false))
                ->when(($filters['status'] ?? '') === 'suspended', fn ($query) => $query->where('is_suspended', true))
                ->when(($filters['status'] ?? '') === 'unverified', fn ($query) => $query->whereNull('email_verified_at'))
                ->when(($filters['status'] ?? '') === 'admins', fn ($query) => $query->where('is_platform_admin', true))
                ->latest('id')->paginate(15, ['*'], 'users_page')->withQueryString(),
            'events' => SecurityEvent::query()->latest('id')->limit(20)->get(['id', 'user_id', 'action', 'created_at']),
        ]);
    }

    public function update(Request $request, User $user, AccountSecurity $security): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['suspend', 'restore', 'revoke_sessions'])]]);
        if ($user->is_platform_admin || $user->is($request->user())) {
            throw ValidationException::withMessages(['action' => 'Administrator hisobini bu paneldan cheklab bo‘lmaydi.']);
        }
        DB::transaction(function () use ($request, $user, $security, $data): void {
            $target = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($target->is_platform_admin) {
                throw ValidationException::withMessages(['action' => 'Administrator hisobini bu paneldan cheklab bo‘lmaydi.']);
            }
            if ($data['action'] !== 'revoke_sessions') {
                $target->forceFill(['is_suspended' => $data['action'] === 'suspend'])->save();
            }
            $security->revoke($target, 'admin.'.$data['action'].'.by.'.$request->user()->id);
            SecurityEvent::create(['user_id' => $request->user()->id, 'action' => 'admin.'.$data['action'].'.user.'.$target->id]);
        });

        return back()->with('success', 'Foydalanuvchi holati yangilandi. Sessiyalari bekor qilindi.');
    }
}
