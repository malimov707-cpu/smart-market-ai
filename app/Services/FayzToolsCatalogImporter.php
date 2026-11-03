<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Offer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use RuntimeException;

class FayzToolsCatalogImporter
{
    /**
     * @return array{organization_id:int, imported:int, skipped:int}
     */
    public function import(?string $source = null, ?int $limit = null): array
    {
        $files = $this->resolveFiles($source);

        if ($files === []) {
            throw new RuntimeException('No FayzTools JSON file was found to import.');
        }

        $organization = Organization::firstOrCreate(
            ['name' => 'FayzTools'],
            ['city' => 'Toshkent'],
        );

        $supplier = Supplier::firstOrCreate(
            ['organization_id' => $organization->id, 'name' => 'FayzTools Catalog'],
            ['city' => 'Toshkent', 'contact' => 'import@fayztools.local'],
        );

        $imported = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $payload = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $products = $payload['productList'] ?? [];

            foreach ($products as $index => $item) {
                if ($limit !== null && $imported >= $limit) {
                    break 2;
                }

                $productId = (string) ($item['productId'] ?? $index + 1);
                $title = trim((string) ($item['title'] ?? $item['skuTitle'] ?? ''));
                if ($title === '') {
                    $skipped++;

                    continue;
                }

                $sku = (string) ($item['skuId'] ?? $productId);
                $unit = $this->detectUnit($title.' '.($item['category'] ?? ''));
                $price = $this->extractPrice($item);

                $product = Product::firstOrCreate(
                    ['organization_id' => $organization->id, 'sku' => $sku],
                    ['name' => $title, 'unit' => $unit, 'category' => $item['category'] ?? null],
                );

                if ($product->wasRecentlyCreated) {
                    $product->update(['name' => $title, 'unit' => $unit, 'category' => $item['category'] ?? null]);
                }

                $sourceReference = 'fayztools:'.$productId;
                $fingerprint = hash('sha256', $organization->id.'|'.$sourceReference.'|'.$price);
                $attributes = [
                    'organization_id' => $organization->id,
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
                    'fingerprint' => $fingerprint,
                ];

                Offer::updateOrCreate(
                    ['organization_id' => $organization->id, 'source_reference' => $sourceReference],
                    $attributes,
                );

                $imported++;
            }
        }

        return ['organization_id' => $organization->id, 'imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * @return list<string>
     */
    protected function resolveFiles(?string $source): array
    {
        if ($source !== null && $source !== '') {
            if (is_file($source)) {
                return [$source];
            }

            if (is_dir($source)) {
                $files = glob($source.'/*.json');

                return $files === false ? [] : array_values(array_filter($files, 'is_file'));
            }
        }

        $roots = array_values(array_filter([
            base_path('database/data'),
            storage_path('app'),
            storage_path('app/fayztools'),
            '/Users/uzmacbook/Desktop',
        ], 'is_string'));

        $files = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $matches = glob($root.'/*.json');
            if ($matches === false) {
                continue;
            }

            foreach ($matches as $candidate) {
                if (is_file($candidate) && preg_match('/(fayztools|response_|catalog).*\.json$/i', basename($candidate)) === 1) {
                    $files[] = $candidate;
                }
            }
        }

        return array_values(array_unique($files));
    }

    protected function extractPrice(array $item): float
    {
        $price = $item['price'] ?? $item['marketPrice'] ?? 0;

        foreach ([$item['skuList'][0]['price'] ?? null, $item['skuList'][0]['marketPrice'] ?? null] as $candidate) {
            if ($candidate !== null && $candidate !== '') {
                $price = $candidate;

                break;
            }
        }

        return (float) $price;
    }

    protected function detectUnit(string $text): string
    {
        $value = strtolower($text);

        if (preg_match('/\b(kg|kilogram|кг|gram|гр|g)\b|\b(kg|кг)\b/i', $value) === 1) {
            return 'kg';
        }

        if (preg_match('/\b(l|liter|ltr|литр|литры|л\.?|ml|мл)\b/i', $value) === 1) {
            return 'l';
        }

        return 'pcs';
    }
}
