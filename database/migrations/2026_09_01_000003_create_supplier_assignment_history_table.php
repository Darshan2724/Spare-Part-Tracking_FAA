<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_assignment_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_assignment_id')->nullable()->constrained('supplier_assignments')->nullOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('jig_no');
            $table->string('unit_no');
            $table->string('category', 20);
            $table->foreignId('previous_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->foreignId('new_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->date('previous_date')->nullable();
            $table->date('new_date')->nullable();
            $table->string('action', 30); // created, updated, removed
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('supplier_assignment_id');
            $table->index(['project_id', 'jig_no', 'unit_no']);
            $table->index('previous_supplier_id');
            $table->index('new_supplier_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_assignment_history');
    }
};
