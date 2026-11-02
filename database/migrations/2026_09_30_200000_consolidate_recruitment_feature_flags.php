<?php

use App\Models\Config\Setting;
use App\Services\SettingService;
use Illuminate\Database\Migrations\Migration;

/**
 * Recruitment was flagged under two names: `recruitment.*` (read by the code) and seven
 * `talent_acquisition.*` flags that nothing read. Remove the unused ones; the `recruitment.*`
 * flags, including the new sub-flags, come from SettingSeeder, which runs on every deploy.
 */
return new class extends Migration {
    public function up(): void
    {
        Setting::where('key', 'like', 'features.talent_acquisition.%')->delete();

        app(SettingService::class)->refreshCache();
    }

    public function down(): void
    {
        // The removed flags were never read; nothing to restore.
    }
};
