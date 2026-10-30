<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

class BrowserSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing') || ! str_contains((string) config('database.connections.sqlite.database'), 'smart-market-browser-')) {
            throw new \RuntimeException('Browser fixtures require an isolated testing database.');
        }
        foreach (['desktop', 'mobile'] as $platform) {
            $user = User::factory()->create(['name' => 'Browser Test Owner', 'email' => $platform.'@example.test', 'password' => 'BrowserPassword123456']);
            $organization = Organization::create(['name' => 'AUTOMATED TEST — '.$platform]);
            $organization->users()->attach($user, ['role' => 'owner']);
            $manager = User::factory()->create(['name' => 'Browser Test Manager', 'email' => 'manager-'.$platform.'@example.test', 'password' => 'BrowserPassword123456']);
            $organization->users()->attach($manager, ['role' => 'manager']);
            $reviewer = User::factory()->create(['email' => 'reviewer-'.$platform.'@example.test', 'password' => 'BrowserPassword123456']);
            $organization->users()->attach($reviewer, ['role' => 'owner']);
        }
        $image = imagecreatetruecolor(16, 16);
        imagepng($image, dirname((string) config('database.connections.sqlite.database')).'/fixture.png');
        imagedestroy($image);
    }
}
