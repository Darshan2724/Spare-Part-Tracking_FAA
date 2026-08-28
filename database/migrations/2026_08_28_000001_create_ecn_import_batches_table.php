<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecn_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('filename');
            $table->string('original_filename')->nullable()->index();
            $table->string('file_hash', 64)->nullable()->index();
            $table->integer('file_size_bytes')->nullable();
            $table->foreignId('imported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->integer('total_rows')->default(0);
            $table->integer('successful_rows')->default(0);
            $table->integer('failed_rows')->default(0);
            $table->integer('added_rows_count')->default(0);
            $table->integer('updated_rows_count')->default(0);
            $table->integer('skipped_rows_count')->default(0);
            $table->integer('conflict_rows_count')->default(0);
            $table->jsonb('ecn_numbers')->nullable();
            $table->jsonb('diff_summary')->nullable();
            $table->jsonb('errors')->nullable();
            $table->enum('status', ['processing', 'completed', 'failed'])->default('processing')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecn_import_batches');
    }
};
