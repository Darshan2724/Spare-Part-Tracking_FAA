<?php

namespace App\Console\Commands;

use Database\Seeders\ScaleTestDatasetSeeder;
use Illuminate\Console\Command;

class SeedScaleTestDataset extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:seed-scale-test-dataset';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed the database with 8 projects and exactly 15,343 parts unevenly distributed across Store, QC, Rework, and Assembly with BOP, STD, MFG, and ECN';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Starting scale test dataset generation (8 projects, 15,343 parts across Store, QC, Rework, Assembly)...');

        $t0 = microtime(true);
        $seeder = new ScaleTestDatasetSeeder();
        $seeder->run();
        $elapsed = round(microtime(true) - $t0, 2);

        $this->info("Scale test dataset seeded successfully in {$elapsed}s!");
        $this->table(
            ['Metric', 'Target Value'],
            [
                ['Projects', '8 Projects (FA-301 to FA-308)'],
                ['Total Parts', '15,343'],
                ['Parts in Store', '5,420 (35.3%)'],
                ['Parts in QC', '4,115 (26.8%)'],
                ['Parts in Rework', '1,673 (10.9%)'],
                ['Parts in Assembly', '4,135 (27.0%)'],
                ['Part Types', 'MFG, BOP, STD in all 8 projects'],
                ['ECN Coverage', 'Active ECNs across Store, QC, Rework, Assembly in all 8 projects'],
            ]
        );

        return self::SUCCESS;
    }
}
