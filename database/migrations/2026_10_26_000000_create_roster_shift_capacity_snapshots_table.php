<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_shift_capacity_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('roster_shift_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('main_count');
            $table->unsignedTinyInteger('optional_count');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_shift_capacity_snapshots');
    }
};
