<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekend_pair_coordination', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('weekend_pair_coordination')->insert(['id' => 1]);
    }

    public function down(): void
    {
        Schema::dropIfExists('weekend_pair_coordination');
    }
};
