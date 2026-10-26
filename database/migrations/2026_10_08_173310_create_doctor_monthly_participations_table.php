<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_monthly_participations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('roster_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->boolean('is_participating');
            $table->timestamps();
            $table->unique(['doctor_id', 'year', 'month']);
            $table->index(['year', 'month', 'is_participating']);
        });

        $doctorIds = DB::table('doctors')->pluck('id')->all();
        $periods = [];

        foreach (DB::table('rosters')->get(['id', 'year', 'month']) as $roster) {
            $periods["{$roster->year}-{$roster->month}"] = [
                'year' => $roster->year,
                'month' => $roster->month,
                'roster_id' => $roster->id,
            ];
        }

        foreach (DB::table('doctor_monthly_workloads')->get(['year', 'month']) as $workload) {
            $key = "{$workload->year}-{$workload->month}";
            $periods[$key] ??= [
                'year' => $workload->year,
                'month' => $workload->month,
                'roster_id' => null,
            ];
        }

        $timestamp = now();
        foreach ($periods as $period) {
            $rows = [];

            foreach ($doctorIds as $doctorId) {
                $rows[] = [
                    'doctor_id' => $doctorId,
                    'roster_id' => $period['roster_id'],
                    'year' => $period['year'],
                    'month' => $period['month'],
                    'is_participating' => true,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('doctor_monthly_participations')->insert($chunk);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_monthly_participations');
    }
};
