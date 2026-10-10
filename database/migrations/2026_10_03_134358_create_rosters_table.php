<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rosters', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('participation_snapshot_max_doctor_id')->nullable();
            $table->timestamp('last_generated_at')->nullable();
            $table->string('generated_history_fingerprint', 64)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamp('actual_work_confirmed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('actual_work_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rosters');
    }
};
