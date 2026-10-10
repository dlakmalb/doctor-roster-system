<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->string('request_type');
            $table->date('request_date');
            $table->foreignId('shift_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['doctor_id', 'request_date']);
            $table->index(['request_date', 'request_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_requests');
    }
};
