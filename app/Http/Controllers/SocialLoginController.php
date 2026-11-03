<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SocialLoginController extends Controller
{
    public function googleRedirect(Request $request): RedirectResponse
    {
        $clientId = (string) config('services.google.client_id');
        $clientSecret = (string) config('services.google.client_secret');
        if ($clientId === '' || $clientSecret === '') {
            return $this->failed('Google orqali kirish hozircha sozlanmagan.');
        }

        $state = Str::random(64);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $request->session()->put([
            'google_oauth_state' => $state,
            'google_oauth_verifier' => $verifier,
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $this->googleRedirectUri($request),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986));
    }

    public function googleCallback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull('google_oauth_state');
        $verifier = $request->session()->pull('google_oauth_verifier');
        $state = $request->query('state');
        $code = $request->query('code');

        if (! is_string($expectedState) || ! is_string($state) || ! hash_equals($expectedState, $state)
            || ! is_string($verifier) || ! is_string($code) || $code === '' || $request->query->has('error')) {
            return $this->failed('Google orqali kirish tasdiqlanmadi. Qaytadan urinib ko‘ring.');
        }

        try {
            $tokenResponse = Http::asForm()->acceptJson()->connectTimeout(3)->timeout(10)
                ->post('https://oauth2.googleapis.com/token', [
                    'client_id' => config('services.google.client_id'),
                    'client_secret' => config('services.google.client_secret'),
                    'code' => $code,
                    'code_verifier' => $verifier,
                    'grant_type' => 'authorization_code',
                    'redirect_uri' => $this->googleRedirectUri($request),
                ]);
            if (! $tokenResponse->successful() || ! is_string($tokenResponse->json('access_token'))) {
                Log::warning('social.google_token_exchange_failed', ['status' => $tokenResponse->status()]);

                return $this->failed('Google orqali kirish bajarilmadi. Qaytadan urinib ko‘ring.');
            }

            $profileResponse = Http::acceptJson()->withToken($tokenResponse->json('access_token'))
                ->connectTimeout(3)->timeout(10)->get('https://openidconnect.googleapis.com/v1/userinfo');
        } catch (ConnectionException $error) {
            Log::warning('social.google_connection_failed', ['exception' => $error::class]);

            return $this->failed('Google xizmatiga ulanib bo‘lmadi. Keyinroq qayta urinib ko‘ring.');
        }

        $profile = $profileResponse->json();
        $googleId = is_array($profile) ? ($profile['sub'] ?? null) : null;
        $email = is_array($profile) ? ($profile['email'] ?? null) : null;
        $name = is_array($profile) ? ($profile['name'] ?? null) : null;
        $emailVerified = is_array($profile) ? ($profile['email_verified'] ?? false) : false;

        if (! $profileResponse->successful() || ! is_string($googleId) || $googleId === ''
            || ! is_string($email) || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || ! in_array($emailVerified, [true, 'true'], true)) {
            Log::warning('social.google_profile_rejected', ['status' => $profileResponse->status()]);

            return $this->failed('Google tasdiqlangan email manzilini qaytarmadi.');
        }

        $user = DB::transaction(function () use ($googleId, $email, $name): ?User {
            $identityUser = User::where('google_id', $googleId)->first();
            $emailUser = User::where('email', $email)->first();
            if ($identityUser && $emailUser && ! $identityUser->is($emailUser)) {
                return null;
            }

            $user = $identityUser ?? $emailUser;
            if ($user?->google_id !== null && $user->google_id !== $googleId) {
                return null;
            }
            if ($user && (! $user->is_active || $user->is_suspended)) {
                return null;
            }

            if (! $user) {
                $user = User::create([
                    'name' => is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 120) : $email,
                    'email' => $email,
                    'password' => Str::random(64),
                ]);
            }

            $user->forceFill(['google_id' => $googleId, 'email_verified_at' => $user->email_verified_at ?? now()])->save();

            return $user;
        });

        if (! $user || ! $user->is_active || $user->is_suspended) {
            return $this->failed('Bu hisob orqali kirishga ruxsat yo‘q.');
        }

        return $this->authenticate($request, $user, 'account.login_google');
    }

    public function telegramCallback(Request $request): RedirectResponse
    {
        $expectedState = $request->session()->pull('telegram_login_state');
        $state = $request->query('state');
        $botToken = (string) config('services.telegram.bot_token');
        $data = $request->query->all();
        $hash = $data['hash'] ?? null;
        if (! is_string($expectedState) || ! is_string($state) || ! hash_equals($expectedState, $state)
            || $botToken === '' || ! is_string($hash) || preg_match('/^[a-f0-9]{64}$/i', $hash) !== 1) {
            return $this->failed('Telegram tasdig‘i noto‘g‘ri. Qaytadan urinib ko‘ring.');
        }

        unset($data['hash']);
        unset($data['state']);
        foreach ($data as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                return $this->failed('Telegram tasdig‘i noto‘g‘ri. Qaytadan urinib ko‘ring.');
            }
        }
        ksort($data, SORT_STRING);
        $checkPairs = [];
        foreach ($data as $key => $value) {
            $checkPairs[] = $key.'='.$value;
        }
        $checkString = implode("\n", $checkPairs);
        $expectedHash = hash_hmac('sha256', $checkString, hash('sha256', $botToken, true));

        $id = $data['id'] ?? null;
        $firstName = $data['first_name'] ?? null;
        $authDate = filter_var($data['auth_date'] ?? null, FILTER_VALIDATE_INT);
        if (! hash_equals($expectedHash, strtolower($hash)) || ! is_string($id) || preg_match('/^\d{1,20}$/D', $id) !== 1
            || ! is_string($firstName) || trim($firstName) === '' || $authDate === false
            || $authDate > now()->timestamp + 30 || now()->timestamp - $authDate > 300) {
            return $this->failed('Telegram akkaunti tasdiqlanmadi yoki tasdiq muddati o‘tgan.');
        }

        $name = trim($firstName.' '.(is_string($data['last_name'] ?? null) ? $data['last_name'] : ''));
        $user = DB::transaction(function () use ($id, $name): ?User {
            $user = User::where('telegram_login_id', $id)->first()
                ?? User::where('telegram_chat_id', $id)->first();
            if ($user?->telegram_login_id !== null && $user->telegram_login_id !== $id) {
                return null;
            }
            if ($user && (! $user->is_active || $user->is_suspended)) {
                return null;
            }

            if (! $user) {
                $email = 'telegram-'.$id.'@login.smart-market.invalid';
                if (User::where('email', $email)->exists()) {
                    return null;
                }

                $user = User::create([
                    'name' => mb_substr($name, 0, 120),
                    'email' => $email,
                    'password' => Str::random(64),
                ]);
            }

            $user->forceFill(['telegram_login_id' => $id])->save();

            return $user;
        });

        if (! $user || ! $user->is_active || $user->is_suspended) {
            return $this->failed('Bu Telegram hisobi orqali kirishga ruxsat yo‘q.');
        }

        return $this->authenticate($request, $user, 'account.login_telegram');
    }

    private function googleRedirectUri(Request $request): string
    {
        $configured = trim((string) config('services.google.redirect'));
        $currentHost = $request->getSchemeAndHttpHost();
        $currentCallback = $currentHost.'/auth/google/callback';

        if ($configured === '') {
            return $currentCallback;
        }

        $requestHost = parse_url($currentCallback, PHP_URL_HOST);
        $configHost = parse_url($configured, PHP_URL_HOST);

        if ($requestHost !== null && $configHost !== null && $requestHost === $configHost) {
            return $configured;
        }

        return $currentCallback;
    }

    private function authenticate(Request $request, User $user, string $action): RedirectResponse
    {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('security_version', $user->security_version);
        $request->session()->forget(['organization_id', 'url.intended']);
        SecurityEvent::create(['user_id' => $user->id, 'action' => $action]);

        return to_route($user->is_platform_admin ? 'admin' : 'market');
    }

    private function failed(string $message): RedirectResponse
    {
        return to_route('login')->withErrors(['oauth' => $message]);
    }
}
