<?php

namespace App\Console\Commands;

use App\Enums\Payroll\ApprovalProcess;
use App\Enums\Payroll\ApproverType;
use App\Enums\Payroll\ComponentCalculation;
use App\Models\Payroll\ApprovalWorkflow;
use App\Models\Payroll\ExchangeRate;
use App\Models\Payroll\OvertimeType;
use App\Models\Payroll\PayComponent;
use App\Services\SettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Sets payroll up for an organization from database/seeders/organizations/<org>.payroll.json:
 * flags, settings, exchange rates, pay components, overtime types and approval workflows.
 * Safe to run again: everything is matched by code or name and updated.
 */
class ApplyPayrollPreset extends Command
{
    protected $signature = 'payroll:apply-preset {organization? : defaults to the ORGANIZATION env value} {--dry-run : list problems and what would be set, change nothing}';

    protected $description = 'Apply an organization\'s payroll preset (components, overtime types, workflows, settings)';

    public function handle(SettingService $settings): int
    {
        $org = $this->argument('organization') ?: config('kazi360.organization');
        $path = database_path("seeders/organizations/{$org}.payroll.json");
        if (!$org || !is_file($path)) {
            $this->error("No payroll preset at {$path}.");

            return self::FAILURE;
        }
        $preset = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        if ($problems = $this->problems($preset)) {
            $this->error('The preset isn\'t ready:');
            foreach ($problems as $p) {
                $this->line("  - {$p}");
            }

            return self::FAILURE;
        }
        if ($this->option('dry-run')) {
            $this->info('The preset is ready. It sets ' . count($preset['components'] ?? []) . ' components, ' . count($preset['overtime_types'] ?? []) . ' overtime types and ' . count($preset['workflows'] ?? []) . ' workflows.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($preset, $settings) {
            foreach ($preset['features'] ?? [] as $flag => $on) {
                $settings->set("features.{$flag}", (bool) $on);
            }
            foreach ($preset['settings'] ?? [] as $key => $value) {
                $settings->set($key, $value);
            }
            foreach ($preset['exchange_rates'] ?? [] as $r) {
                ExchangeRate::updateOrCreate(['currency' => strtoupper($r['currency']), 'year' => $r['year']], ['rate' => $r['rate'], 'notes' => $r['notes'] ?? null]);
            }
            foreach ($preset['components'] ?? [] as $c) {
                PayComponent::withTrashed()->updateOrCreate(['code' => $c['code']], collect($c)->except(['code', '_confirm'])->all() + ['active' => true, 'deleted_at' => null]);
            }
            foreach ($preset['overtime_types'] ?? [] as $t) {
                OvertimeType::withTrashed()->updateOrCreate(['name' => $t['name']], [
                    'pay_component_id' => PayComponent::where('code', $t['component'])->value('id'),
                    'applies_on'       => $t['applies_on'],
                    'sort_order'       => $t['sort_order'] ?? 100,
                    'active'           => true,
                    'deleted_at'       => null,
                ]);
            }
            foreach ($preset['workflows'] ?? [] as $w) {
                $process = ApprovalProcess::from($w['process']);
                ApprovalWorkflow::where('process', $process)->update(['is_default' => false]);
                $workflow = ApprovalWorkflow::updateOrCreate(['process' => $process, 'name' => $w['name']], ['is_default' => true, 'distinct_approvers' => $w['distinct_approvers'] ?? false]);
                $workflow->steps()->delete();
                foreach (array_values($w['steps']) as $i => $s) {
                    $workflow->steps()->create([
                        'position' => $i + 1, 'name' => $s['name'], 'approver_type' => $s['approver_type'],
                        'approver_value' => $s['approver_value'] ?? null, 'can_adjust' => $s['can_adjust'] ?? [],
                    ]);
                }
            }
        });
        $settings->refreshCache();

        $this->info("Payroll preset \"{$org}\" applied. Check it in Payroll → Settings, and confirm the statutory rates there before the first run.");

        return self::SUCCESS;
    }

    /** What stops the preset from loading, in words. */
    private function problems(array $preset): array
    {
        $problems = [];
        if (empty($preset['confirmed'])) {
            $problems[] = 'It isn\'t confirmed: check every CONFIRM value with the organization, then set "confirmed": true.';
        }
        foreach ($preset['exchange_rates'] ?? [] as $r) {
            if (!is_numeric($r['rate'] ?? null) || $r['rate'] <= 0) {
                $problems[] = "Exchange rate {$r['currency']} {$r['year']} has no rate.";
            }
        }
        $codes = [];
        foreach ($preset['components'] ?? [] as $c) {
            $codes[] = $c['code'];
            $calculation = ComponentCalculation::tryFrom($c['calculation'] ?? '');
            if (!$calculation) {
                $problems[] = "Component {$c['code']}: unknown calculation.";
            } elseif ($calculation->needsRate() && !is_numeric($c['rate'] ?? null)) {
                $problems[] = "Component {$c['code']} has no rate" . (isset($c['_confirm']) ? " ({$c['_confirm']})." : '.');
            }
        }
        foreach ($preset['overtime_types'] ?? [] as $t) {
            if (!in_array($t['component'], $codes, true) && !PayComponent::where('code', $t['component'])->exists()) {
                $problems[] = "Overtime type {$t['name']}: no component {$t['component']}.";
            }
            if (!array_key_exists($t['applies_on'] ?? '', OvertimeType::APPLIES_ON)) {
                $problems[] = "Overtime type {$t['name']}: unknown applies_on.";
            }
        }
        foreach ($preset['workflows'] ?? [] as $w) {
            $process = ApprovalProcess::tryFrom($w['process'] ?? '');
            if (!$process) {
                $problems[] = "Workflow {$w['name']}: unknown process.";
                continue;
            }
            foreach ($w['steps'] ?? [] as $s) {
                if (!ApproverType::tryFrom($s['approver_type'] ?? '')) {
                    $problems[] = "Workflow {$w['name']}, step {$s['name']}: unknown approver type.";
                }
                if ($bad = array_diff($s['can_adjust'] ?? [], array_keys($process->adjustable()))) {
                    $problems[] = "Workflow {$w['name']}, step {$s['name']}: can't adjust " . implode(', ', $bad) . '.';
                }
            }
        }

        return $problems;
    }
}
