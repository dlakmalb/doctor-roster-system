<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_monthly_workloads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('roster_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('source')->default('system');
            $table->unsignedInteger('actual_worked_minutes');
            $table->integer('opening_balance_minutes')->default(0);
            $table->integer('monthly_adjustment_minutes')->default(0);
            $table->integer('closing_balance_minutes')->default(0);
            $table->unsignedSmallInteger('actual_night_duty_count')->default(0);
            $table->unsignedSmallInteger('optional_assignment_count')->default(0);
            $table->boolean('worked_final_weekend')->default(false);
            $table->timestamp('most_recent_night_shift_at')->nullable();
            $table->boolean('is_month_excluded')->default(false);
            $table->timestamps();
            $table->unique(['doctor_id', 'year', 'month']);
            $table->index(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_monthly_workloads');
    }
};
