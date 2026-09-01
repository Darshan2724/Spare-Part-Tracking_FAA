<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            if (!Schema::hasColumn('suppliers', 'pincode')) {
                $table->string('pincode', 20)->nullable()->after('city');
            }
        });

        if (!Schema::hasTable('supplier_phones')) {
            Schema::create('supplier_phones', function (Blueprint $table) {
                $table->id();
                $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
                $table->string('phone_number', 50)->index();
                $table->string('label', 50)->nullable(); // e.g. 'Primary', 'Office', 'Mobile'
                $table->boolean('is_primary')->default(false);
                $table->timestamps();

                $table->index(['supplier_id', 'phone_number']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_phones');

        Schema::table('suppliers', function (Blueprint $table) {
            if (Schema::hasColumn('suppliers', 'pincode')) {
                $table->dropColumn('pincode');
            }
        });
    }
};
