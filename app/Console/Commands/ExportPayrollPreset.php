<?php

namespace App\Console\Commands;

use App\Models\Config\Setting;
use App\Models\Payroll\ApprovalWorkflow;
use App\Models\Payroll\CostCentre;
use App\Models\Payroll\ExchangeRate;
use App\Models\Payroll\OvertimeType;
use App\Models\Payroll\PayComponent;
use App\Models\Payroll\WorkSite;
use Illuminate\Console\Command;

/**
 * Writes this deployment's payroll setup as a preset (database/seeders/organizations/<org>.payroll.json), in the
 * format payroll:apply-preset reads: the payroll feature flags and settings, the latest exchange rate of each
 * currency and type, active sites, cost centres, pay components (not the system ones), overtime types and the
 * default approval workflows. Set up once (on the screens), export, and apply it elsewhere or rebuild from it.
 * Not included: statutory rates (confirmed per deployment), banks and payment file layouts, employees' pay.
 */
class ExportPayrollPreset extends Command
{
    protected $signature = 'payroll:export-preset
        {organization? : the preset name; defaults to the ORGANIZATION env value}
        {--path= : where to write it (default database/seeders/organizations/<organization>.payroll.json)}
        {--force : replace the file if it exists}
        {--stdout : print it instead of writing a file}';

    protected $description = 'Export this deployment\'s payroll setup as a preset (the reverse of payroll:apply-preset)';

    public function handle(): int
    {
        $org = $this->argument('organization') ?: config('kazi360.organization');
        if (!$org && !$this->option('stdout')) {
            $this->error('Name the preset (php artisan payroll:export-preset <organization>) or set ORGANIZATION.');

            return self::FAILURE;
        }
        $json = json_encode($this->preset($org), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

        if ($this->option('stdout')) {
            $this->output->write($json);

            return self::SUCCESS;
        }
        $path = $this->option('path') ?: database_path("seeders/organizations/{$org}.payroll.json");
        if (is_file($path) && !$this->option('force')) {
            $this->error("{$path} exists. Use --force to replace it (or --path to write elsewhere).");

            return self::FAILURE;
        }
        file_put_contents($path, $json);
        $this->info("Payroll preset written to {$path}. Apply it with: php artisan payroll:apply-preset {$org}");

        return self::SUCCESS;
    }

    /** The setup as a preset. */
    public function preset(?string $org): array
    {
        $settings = Setting::query()->orderBy('key')->get(['key', 'value']);

        return [
            '_note'          => 'Exported from ' . ($org ?: config('app.name')) . ' on ' . now()->toDateString() . ' (php artisan payroll:export-preset). Statutory rates, banks and payment file layouts aren\'t included.',
            'confirmed'      => true,
            'features'       => $settings->filter(fn ($s) => str_starts_with($s->key, 'features.payroll.'))
                ->mapWithKeys(fn ($s) => [substr($s->key, strlen('features.')) => (bool) $s->value])->all(),
            'settings'       => $settings->filter(fn ($s) => str_starts_with($s->key, 'payroll.'))
                ->mapWithKeys(fn ($s) => [$s->key => $s->value])->all(),
            // The latest of each currency and type: the rate to start from, not the history.
            'exchange_rates' => ExchangeRate::orderByDesc('effective_date')->get()->unique(fn ($r) => "{$r->currency}|{$r->rate_type}")
                ->sortBy(['currency', 'rate_type'])->map(fn ($r) => array_filter([
                    'currency' => $r->currency, 'rate_type' => $r->rate_type, 'effective_date' => $r->effective_date->toDateString(),
                    'rate' => (float) $r->rate, 'notes' => $r->notes,
                ], fn ($v) => $v !== null))->values()->all(),
            'work_sites'     => WorkSite::where('active', true)->orderBy('code')->get()
                ->map(fn ($s) => ['code' => $s->code, 'name' => $s->name, 'is_offshore' => (bool) $s->is_offshore])->all(),
            'cost_centres'   => CostCentre::where('active', true)->orderBy('code')->get()
                ->map(fn ($c) => ['code' => $c->code, 'name' => $c->name])->all(),
            // The system components (basic, days worked, loans, arrears) come with every deployment.
            'components'     => PayComponent::where('active', true)->where('is_system', false)->orderBy('sort_order')->orderBy('code')->get()
                ->map(fn ($c) => $this->component($c))->all(),
            'overtime_types' => OvertimeType::where('active', true)->with('component')->orderBy('sort_order')->get()
                ->filter(fn ($t) => $t->component)
                ->map(fn ($t) => ['name' => $t->name, 'component' => $t->component->code, 'applies_on' => $t->applies_on, 'sort_order' => (int) $t->sort_order])->values()->all(),
            'workflows'      => ApprovalWorkflow::where('is_default', true)->with(['steps' => fn ($q) => $q->orderBy('position')])->get()
                ->sortBy(fn ($w) => $w->process->value)->map(fn ($w) => [
                    'process'            => $w->process->value,
                    'name'               => $w->name,
                    'distinct_approvers' => (bool) $w->distinct_approvers,
                    'steps'              => $w->steps->map(fn ($s) => array_filter([
                        'name'           => $s->name,
                        'approver_type'  => $s->approver_type->value,
                        'approver_value' => $s->approver_value ?: null,
                        'can_adjust'     => $s->can_adjust ?: null,
                        'questions'      => $s->questions ?: null,
                    ], fn ($v) => $v !== null))->values()->all(),
                ])->values()->all(),
        ];
    }

    /** A component as the preset gives it: every field apply-preset sets, without ids or empty ones. */
    private function component(PayComponent $c): array
    {
        $raw = $c->getAttributes();

        return collect($c->getFillable())
            ->reject(fn ($f) => in_array($f, ['active', 'is_system'], true))
            ->mapWithKeys(fn ($f) => [$f => match (true) {
                $raw[$f] === null                                 => null,
                in_array($f, ['rate'], true)                      => (float) $raw[$f],
                in_array($f, ['sort_order'], true)                => (int) $raw[$f],
                $c->hasCast($f, ['bool', 'boolean'])               => (bool) $raw[$f],
                default                                           => $raw[$f],
            }])
            ->filter(fn ($v) => $v !== null)
            ->all();
    }
}
