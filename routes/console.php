<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('activitylog:clean')->monthly();

Artisan::command('bom:seed', function (\App\Services\BomImportService $service) {
    $user = \App\Models\User::first();
    $files = \Illuminate\Support\Facades\File::files(base_path('BOM'));
    foreach ($files as $file) {
        $filename = $file->getFilename();
        $this->info("Importing {$filename}...");
        try {
            $code = \Illuminate\Support\Str::before($filename, '_ERP');
            $name = str_contains($filename, '62800') ? 'XYZ' : $code;
            if (str_contains($filename, '62800')) $code = 'FAA-1';
            $res = $service->importFromPath($file->getRealPath(), [
                'filename' => $filename,
                'project_code' => $code,
                'project_name' => $name,
            ], $user->id);
            if (!empty($res['errors'])) {
                $this->error("Errors in {$filename}: " . json_encode($res['errors']));
            } else {
                $this->info("Imported batch successfully.");
            }
        } catch (\Throwable $e) {
            $this->error("Error importing {$filename}: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        }
    }
});
