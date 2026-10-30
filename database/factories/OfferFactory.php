<?php

namespace Database\Factories;

use App\Models\Offer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(), 'product_id' => fn (array $attrs) => Product::factory()->create(['organization_id' => $attrs['organization_id']])->id, 'supplier_id' => fn (array $attrs) => Supplier::factory()->create(['organization_id' => $attrs['organization_id']])->id, 'price' => '10000.00', 'currency' => 'UZS', 'unit' => 'kg', 'package_quantity' => '1.000', 'minimum_packages' => 1, 'delivery_fee' => '0.00', 'observed_at' => now(), 'provenance' => 'manual', 'source_reference' => 'TEST ONLY', 'fingerprint' => fake()->unique()->sha256(),
        ];
    }
}
