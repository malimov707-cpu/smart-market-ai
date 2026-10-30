<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExchangeRates
{
    public const CURRENCIES = ['UZS', 'USD', 'EUR', 'RUB'];

    public function record(User $actor, Organization $organization, array $input): ExchangeRate
    {
        Gate::forUser($actor)->authorize('administer', $organization);
        $data = Validator::make($input, [
            'currency' => ['required', Rule::in(array_diff(self::CURRENCIES, ['UZS']))],
            'rate' => ['required', 'regex:/^\d{1,16}(?:\.\d{1,12})?$/', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_scalar($value) || ! preg_match('/^\d{1,16}(?:\.\d{1,12})?$/', (string) $value) || bccomp((string) $value, '0', 12) <= 0) {
                    $fail('Kurs musbat decimal bo‘lsin.');
                }
            }],
            'source' => ['required', 'string', 'min:3', 'max:255'], 'source_reference' => ['required', 'string', 'min:3', 'max:255'],
            'effective_at' => ['required', 'date', 'before_or_equal:now'], 'expires_at' => ['required', 'date', 'after:effective_at'],
        ])->validate();
        foreach (['effective_at', 'expires_at'] as $field) {
            $data[$field] = CarbonImmutable::parse($data[$field])->utc();
        }

        return DB::transaction(function () use ($actor, $organization, $data): ExchangeRate {
            $rate = ExchangeRate::create([...$data, 'organization_id' => $organization->id, 'submitted_by' => $actor->id]);
            $this->audit($actor, $rate, 'recorded');

            return $rate;
        });
    }

    public function approve(User $actor, Organization $organization, int $id): void
    {
        Gate::forUser($actor)->authorize('administer', $organization);
        DB::transaction(function () use ($actor, $organization, $id): void {
            $rate = ExchangeRate::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($id);
            if ($rate->submitted_by === $actor->id || $rate->approved_at !== null || $rate->expires_at->lte(now())) {
                throw ValidationException::withMessages(['rate' => 'Faol kursni boshqa Owner bir marta tasdiqlashi kerak.']);
            }
            $rate->forceFill(['approved_by' => $actor->id, 'approved_at' => now()])->save();
            $this->audit($actor, $rate, 'approved');
        });
    }

    public function at(int $tenant, CarbonImmutable $asOf): array
    {
        $ranked = ExchangeRate::where('organization_id', $tenant)->where('effective_at', '<=', $asOf->utc()->format('Y-m-d H:i:s.u'))
            ->where('created_at', '<=', $asOf->utc()->format('Y-m-d H:i:s.u'))->where('approved_at', '<=', $asOf->utc()->format('Y-m-d H:i:s.u'))
            ->select('exchange_rates.*')->selectRaw('ROW_NUMBER() OVER (PARTITION BY currency ORDER BY effective_at DESC, created_at DESC, id DESC) AS rate_rank');

        return ExchangeRate::fromSub($ranked, 'exchange_rates')->where('rate_rank', 1)->get()->keyBy('currency')->all();
    }

    /** One foreign unit = rate UZS. Round positive amounts half-up only at final money boundary. */
    public function convert(string $amount, string $currency, array $rates, CarbonImmutable $asOf, int $scale = 2): ?string
    {
        if ($currency === 'UZS') {
            return bcadd($amount, '0', $scale);
        }
        $rate = $rates[$currency] ?? null;
        if (! $rate || $rate->expires_at->lte($asOf)) {
            return null;
        }
        $value = bcmul($amount, $rate->rate, 24);

        return bcadd($value, '0.'.str_repeat('0', $scale).'5', $scale);
    }

    public function unitPrice(string $price, string $packageSize, string $currency, array $rates, CarbonImmutable $asOf): ?string
    {
        $converted = $this->convert($price, $currency, $rates, $asOf, 24);
        if ($converted === null) {
            return null;
        }
        // Scale before dividing: keep the rounding digit without truncating the original ratio.
        $scaled = bcdiv(bcmul($converted, '1000000000000', 24), $packageSize, 1);

        return bcdiv(bcadd($scaled, '0.5', 0), '1000000000000', 12);
    }

    private function audit(User $actor, ExchangeRate $rate, string $action): void
    {
        AuditLog::create(['organization_id' => $rate->organization_id, 'user_id' => $actor->id, 'action' => 'exchange_rate.'.$action, 'subject_type' => 'exchange_rate', 'subject_id' => $rate->id]);
    }
}
