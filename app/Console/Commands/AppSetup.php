<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as CommandAlias;

class AppSetup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:setup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(): int
    {
        try {
            $this->output->title('Application Setup Started');

            $this->output->title('Applying migrations');
            Artisan::call('migrate:fresh');

            $this->output->title('Seeding data');
            Artisan::call('db:seed');

            $this->output->title('Import Employee data');
            Artisan::call('import:excel');

            $this->output->title('Update Holidays');
            Artisan::call('update:holidays');

            $this->output->success('Setup Complete');

            return CommandAlias::SUCCESS;
        } catch (\Exception $exception) {

            Log::info($exception);
            $this->output->error($exception->getMessage());
            return CommandAlias::FAILURE;
        }
    }
}
