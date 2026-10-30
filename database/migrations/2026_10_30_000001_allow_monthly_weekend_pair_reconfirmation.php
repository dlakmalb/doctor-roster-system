<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekend_pair_configurations', function (Blueprint $table): void {
            $table->dropUnique(['group_code', 'effective_saturday']);
            $table->index(['group_code', 'effective_saturday', 'version'], 'wpc_effective_version_index');
        });
        Schema::table('monthly_weekend_pair_confirmations', function (Blueprint $table): void {
            $table->dropUnique(['year', 'month']);
            $table->index(['year', 'month', 'id'], 'mwpc_month_history_index');
            $table->foreignId('supersedes_confirmation_id')->nullable()
                ->constrained('monthly_weekend_pair_confirmations', indexName: 'mwpc_supersedes_fk')->restrictOnDelete();
            $table->unique('supersedes_confirmation_id', 'mwpc_supersedes_unique');
        });
    }

    public function down(): void
    {
        throw new LogicException('Re-confirmation history cannot be rolled back without discarding immutable records. Use a forward migration.');
    }
};
