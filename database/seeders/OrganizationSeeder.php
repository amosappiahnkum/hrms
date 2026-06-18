<?php

namespace Database\Seeders;

use App\Models\Config\Setting;
use Illuminate\Database\Seeder;

class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        $org = config('kazi360.organization');
        $file = database_path("seeders/organizations/{$org}.json");

        if (!file_exists($file)) {
            $this->command->warn("Organization file not found: {$file}");
            return;
        }

        $entries = json_decode(file_get_contents($file), true);

        foreach ($entries as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                [
                    'value' => $value,
                    'group' => 'company',
                    'is_public' => false,
                ]
            );
        }

        $this->command->info("Organization seeded from: {$org}.json");
    }
}
