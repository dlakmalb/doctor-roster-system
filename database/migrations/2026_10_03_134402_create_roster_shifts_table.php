<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roster_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_type_id')->constrained()->restrictOnDelete();
            $table->date('shift_date');
            $table->timestamps();
            $table->unique(['roster_id', 'shift_date', 'shift_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_shifts');
    }
};
