<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monthly_weekend_pair_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('group_a_configuration_id')->constrained('weekend_pair_configurations', indexName: 'mwpc_group_a_config_fk')->restrictOnDelete();
            $table->foreignId('group_b_configuration_id')->constrained('weekend_pair_configurations', indexName: 'mwpc_group_b_config_fk')->restrictOnDelete();
            $table->foreignId('boundary_configuration_id')->nullable()->constrained('weekend_pair_configurations', indexName: 'mwpc_boundary_config_fk')->restrictOnDelete();
            $table->string('population_fingerprint', 64);
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monthly_weekend_pair_confirmations');
    }
};
