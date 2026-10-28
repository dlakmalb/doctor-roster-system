<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekend_pair_configurations', function (Blueprint $table): void {
            $table->id();
            $table->string('group_code', 1);
            $table->date('effective_saturday');
            $table->unsignedInteger('version');
            $table->unsignedTinyInteger('mode');
            $table->date('pair_anchor_saturday');
            $table->unsignedTinyInteger('pair_anchor_phase');
            $table->unsignedTinyInteger('extra_day_anchor_phase');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['group_code', 'effective_saturday']);
            $table->unique(['group_code', 'version']);
        });

        Schema::create('weekend_pair_configuration_doctors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weekend_pair_configuration_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('position');
            $table->unique(['weekend_pair_configuration_id', 'position'], 'weekend_pair_position_unique');
            $table->unique(['weekend_pair_configuration_id', 'doctor_id'], 'weekend_pair_doctor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekend_pair_configuration_doctors');
        Schema::dropIfExists('weekend_pair_configurations');
    }
};
