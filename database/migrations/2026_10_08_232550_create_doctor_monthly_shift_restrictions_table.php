<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_monthly_shift_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('shift_type_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->unique(['doctor_id', 'year', 'month', 'shift_type_id'], 'doctor_monthly_shift_restrictions_unique');
            $table->index(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_monthly_shift_restrictions');
    }
};
