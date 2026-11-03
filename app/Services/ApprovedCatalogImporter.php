<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Offer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use RuntimeException;

class ApprovedCatalogImporter
{
    /**
     * @return array{organization_id:int, supplier_id:int, imported:int, skipped:int}
     */
    public function import(?string $file = null): array
    {
        $path = $file ?? base_path('database/data/approved-market-catalog.json');
        if (! is_file($path)) {
            throw new RuntimeException('Approved catalog file was not found.');
        }

        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $rows = is_array($payload) && array_is_list($payload) ? $payload : ($payload['products'] ?? []);
        if (! is_array($rows) || $rows === []) {
            throw new RuntimeException('Approved catalog is empty or malformed.');
        }

        $organization = Organization::firstOrCreate(
            ['name' => 'Approved Catalog'],
            ['city' => 'Toshkent'],
        );

        $supplier = Supplier::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'Approved Catalog'],
            ['city' => 'Toshkent', 'contact' => 'catalog@internal.local'],
        );

        $imported = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;

                continue;
            }

            $name = trim((string) ($row['name'] ?? ''));
            $sku = trim((string) ($row['sku'] ?? $row['slug'] ?? $name));
            if ($name === '' || $sku === '') {
                $skipped++;

                continue;
            }

            $category = trim((string) ($row['category'] ?? 'Umumiy'));
            $brand = trim((string) ($row['brand'] ?? ''));
            $unit = trim((string) ($row['unit'] ?? 'pcs'));
            $price = $this->coercePrice($row['price'] ?? $row['price_uzs'] ?? 0);
            $aliases = array_values(array_filter(array_map('trim', (array) ($row['aliases'] ?? []))));
            if ($brand !== '') {
                $aliases[] = $brand;
            }
            $aliases = array_values(array_unique($aliases));

            $product = Product::firstOrCreate(
                ['organization_id' => $organization->id, 'sku' => $sku],
                ['name' => $name, 'unit' => $unit, 'category' => $category, 'aliases' => $aliases],
            );

            $product->update([
                'name' => $name,
                'unit' => $unit,
                'category' => $category,
                'aliases' => $aliases,
            ]);

            $sourceReference = 'approved:'.$sku;
            Offer::updateOrCreate(
                ['organization_id' => $organization->id, 'source_reference' => $sourceReference],
                [
                    'product_id' => $product->id,
                    'supplier_id' => $supplier->id,
                    'price' => number_format($price, 2, '.', ''),
                    'currency' => 'UZS',
                    'unit' => $unit,
                    'package_quantity' => '1.000',
                    'minimum_packages' => 1,
                    'delivery_fee' => '0.00',
                    'observed_at' => now()->toDateTimeString(),
                    'provenance' => 'csv',
                    'source_reference' => $sourceReference,
                    'fingerprint' => hash('sha256', $organization->id.'|'.$sourceReference.'|'.$price),
                ],
            );

            $imported++;
        }

        return ['organization_id' => $organization->id, 'supplier_id' => $supplier->id, 'imported' => $imported, 'skipped' => $skipped];
    }

    private function coercePrice(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value)) {
            $clean = preg_replace('/[^0-9,\.]/', '', $value);
            if ($clean === '') {
                return 0.0;
            }

            return (float) str_replace(',', '.', $clean);
        }

        return 0.0;
    }
}
