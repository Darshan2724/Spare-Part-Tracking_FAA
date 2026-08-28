<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ecn_workflow_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ecn_requirement_id')->constrained('ecn_requirements')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete()->index();
            $table->string('ecn_number')->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type')->index();
            $table->string('side', 10);
            $table->string('side_display', 10);
            $table->integer('quantity');
            $table->string('previous_state')->nullable();
            $table->string('new_state')->nullable();
            $table->text('remarks')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['ecn_requirement_id', 'event_type'], 'ecn_event_req_type_idx');
            $table->index(['project_id', 'event_type'], 'ecn_event_proj_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ecn_workflow_events');
    }
};
