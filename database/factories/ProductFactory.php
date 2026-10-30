<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(), 'name' => fake()->words(3, true), 'sku' => fake()->unique()->bothify('SKU-######'), 'unit' => 'kg', 'category' => 'Test data',
        ];
    }
}
