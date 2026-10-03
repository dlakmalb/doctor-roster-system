<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actual_work_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roster_shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('planned_assignment_id')->nullable()->constrained('roster_assignments')->nullOnDelete();
            $table->string('exception_type');
            $table->foreignId('actual_doctor_id')->nullable()->constrained('doctors')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actual_work_exceptions');
    }
};
