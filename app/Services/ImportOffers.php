<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Requests\MarketRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ImportOffers
{
    public const COLUMNS = ['product_id', 'supplier_id', 'price', 'currency', 'unit', 'package_quantity', 'minimum_packages', 'delivery_fee', 'observed_at', 'expires_at', 'source_reference'];

    public function execute(User $user, Organization $organization, UploadedFile $file): array
    {
        Gate::forUser($user)->authorize('manage', $organization);
        $handle = fopen($file->getRealPath(), 'r');
        try {
            $headers = fgetcsv($handle, 8192, ',', '"', '');
            if ($headers) {
                $headers[0] = ltrim($headers[0], "\xEF\xBB\xBF");
            }
            if ($headers !== self::COLUMNS) {
                throw ValidationException::withMessages(['file' => 'CSV ustunlari namunaga mos bo‘lishi kerak.']);
            }
            $rows = [];
            while (($row = fgetcsv($handle, 8192, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue;
                }
                if (count($rows) >= 500 || count($row) !== count($headers)) {
                    throw ValidationException::withMessages(['file' => 'Ko‘pi bilan 500 satr; har satrda barcha ustunlar bo‘lishi kerak.']);
                }
                $data = array_combine($headers, array_map(fn ($value) => $value === '' ? null : $value, $row));
                $validator = Validator::make($data, MarketRequest::offerRules());
                if ($validator->fails()) {
                    throw ValidationException::withMessages(['file' => 'Satr '.(count($rows) + 2).': '.$validator->errors()->first()]);
                }
                $rows[] = $validator->validated();
            }
            if ($rows === []) {
                throw ValidationException::withMessages(['file' => 'CSV faylida takliflar yo‘q.']);
            }

            return DB::transaction(function () use ($rows, $user, $organization): array {
                $created = 0;
                foreach ($rows as $row) {
                    $offer = app(RecordOffer::class)->execute($user, $organization, $row, 'csv');
                    if ($offer->wasRecentlyCreated) {
                        $created++;
                    }
                }

                return ['created' => $created, 'duplicates' => count($rows) - $created];
            });
        } finally {
            fclose($handle);
        }
    }
}
