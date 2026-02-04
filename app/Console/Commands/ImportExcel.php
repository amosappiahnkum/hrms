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

        $files = File::allFiles(public_path('data'));

        foreach ($files as $file) {
            (new EmployeeImport)->withOutput($this->output)->import($file->getRealPath());
        }

        $this->output->success('Import successful');
    }
}
