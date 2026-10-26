<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_monthly_night_boundary_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('night_date');
            $table->timestamps();
            $table->unique(['year', 'month']);
        });

        Schema::create('doctor_monthly_night_boundary_doctors', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('snapshot_id')->constrained('doctor_monthly_night_boundary_snapshots')->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['snapshot_id', 'doctor_id'], 'dmnb_doctors_snapshot_doctor_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_monthly_night_boundary_doctors');
        Schema::dropIfExists('doctor_monthly_night_boundary_snapshots');
    }
};
