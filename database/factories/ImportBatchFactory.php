<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ImportBatch;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ImportBatch> */
class ImportBatchFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory(), 'user_id' => User::factory(), 'type' => 'products', 'format' => 'csv', 'file_name' => 'TEST-ONLY.csv',
            'file_path' => 'test-only/'.fake()->uuid().'.csv', 'file_mime' => 'text/csv', 'file_size' => 10, 'file_hash' => fake()->sha256(),
            'idempotency_key' => fake()->unique()->sha256(), 'timezone' => 'UTC', 'expires_at' => now()->addDays(7)];
    }
}
