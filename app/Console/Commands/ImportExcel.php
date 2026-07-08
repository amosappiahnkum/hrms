<?php

namespace App\Console\Commands;

use App\Imports\EmployeeImport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ImportExcel extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'import:excel';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Laravel Excel Importer';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->output->title('Starting import');

        $org  = config('kazi360.organization');
        $file = database_path("seeders/organizations/{$org}_data.xlsx");

        if (!file_exists($file)) {
            $this->output->warning("Employee file not found: {$file}");
            return;
        }

//        (new EmployeeImport)->withOutput($this->output)->import($file);

        $this->output->success('Import successful');
    }
}
