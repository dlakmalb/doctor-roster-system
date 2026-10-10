<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roster_shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->string('role');
            $table->unsignedTinyInteger('slot_number');
            $table->timestamps();
            $table->unique(['roster_shift_id', 'doctor_id']);
            $table->unique(['roster_shift_id', 'role', 'slot_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_assignments');
    }
};
