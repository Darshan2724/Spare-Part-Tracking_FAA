<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->string('severity', 20)->default('INFO'); // INFO, WARNING, ERROR, CRITICAL
            $table->string('category', 50); // application_errors, api_errors, database_errors, authentication_logs, authorization_logs, workflow_errors, realtime_logs, file_and_upload_logs, system_health_logs
            $table->string('module', 50)->nullable(); // AUTH, STORE, QC, REWORK, PAINT, ASSEMBLY, PURCHASE, API, SYSTEM, DATABASE, REALTIME
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_role', 50)->nullable();
            $table->string('trace_id', 64)->nullable()->index();
            $table->string('endpoint', 255)->nullable();
            $table->string('method', 10)->nullable();
            $table->integer('status_code')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('message');
            $table->jsonb('details')->nullable();
            $table->string('status', 20)->default('new'); // new, reviewed, resolved
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            // PostgreSQL Indexes for lightning fast filtering
            $table->index('severity');
            $table->index('category');
            $table->index('module');
            $table->index('status');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};
