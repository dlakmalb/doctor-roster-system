<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekend_rotation_configurations', function (Blueprint $table): void {
            $table->id();
            $table->string('singleton_key', 16)->default('primary')->unique();
            $table->date('anchor_saturday');
            $table->string('anchor_group', 1);
            $table->timestamps();
        });

        Schema::create('doctor_weekend_group_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->string('group_code', 1)->nullable();
            $table->date('effective_from_saturday');
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['doctor_id', 'effective_from_saturday'], 'doctor_weekend_group_effective_unique');
            $table->index(
                ['effective_from_saturday', 'group_code'],
                'dwgm_effective_group_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_weekend_group_memberships');
        Schema::dropIfExists('weekend_rotation_configurations');
    }
};
