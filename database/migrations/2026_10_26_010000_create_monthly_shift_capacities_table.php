<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = $this->assertSupportedDriver();

        Schema::create('monthly_shift_capacities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->foreignId('shift_type_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('main_count');
            $table->unsignedTinyInteger('optional_count');
            $table->timestamps();
            $table->unique(['year', 'month', 'shift_type_id'], 'monthly_shift_capacities_period_shift_unique');
        });

        $this->createCapacityIntegrityTriggers($driver);
    }

    public function down(): void
    {
        $driver = $this->assertSupportedDriver();
        $this->dropCapacityIntegrityTriggers($driver);
        Schema::dropIfExists('monthly_shift_capacities');
    }

    private function assertSupportedDriver(): string
    {
        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            throw new RuntimeException("Monthly shift capacity constraints are not configured for the {$driver} database driver.");
        }

        return $driver;
    }

    private function createCapacityIntegrityTriggers(string $driver): void
    {
        $connection = DB::connection();
        $table = $connection->getQueryGrammar()->wrapTable('monthly_shift_capacities');
        $insertTrigger = $connection->getQueryGrammar()->wrap('monthly_shift_capacities_validate_insert');
        $updateTrigger = $connection->getQueryGrammar()->wrap('monthly_shift_capacities_validate_update');

        if ($driver === 'sqlite') {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
                $trigger = $suffix === 'insert' ? $insertTrigger : $updateTrigger;
                $connection->statement("CREATE TRIGGER {$trigger} BEFORE {$event} ON {$table} FOR EACH ROW
                    BEGIN
                        SELECT CASE WHEN typeof(NEW.year) != 'integer' OR NEW.year < 1 OR NEW.year > 65535 THEN RAISE(ABORT, 'year must be an integer from 1 through 65535') END;
                        SELECT CASE WHEN typeof(NEW.month) != 'integer' OR NEW.month < 1 OR NEW.month > 12 THEN RAISE(ABORT, 'month must be an integer from 1 through 12') END;
                        SELECT CASE WHEN typeof(NEW.main_count) != 'integer' OR NEW.main_count < 0 OR NEW.main_count > 255 THEN RAISE(ABORT, 'main_count must be an integer from 0 through 255') END;
                        SELECT CASE WHEN typeof(NEW.optional_count) != 'integer' OR NEW.optional_count < 0 OR NEW.optional_count > 255 THEN RAISE(ABORT, 'optional_count must be an integer from 0 through 255') END;
                    END");
            }

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $suffix => $event) {
                $trigger = $suffix === 'insert' ? $insertTrigger : $updateTrigger;
                $connection->unprepared("CREATE TRIGGER {$trigger} BEFORE {$event} ON {$table} FOR EACH ROW
                    BEGIN
                        IF NEW.year < 1 OR NEW.year > 65535 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'year must be from 1 through 65535'; END IF;
                        IF NEW.month < 1 OR NEW.month > 12 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'month must be from 1 through 12'; END IF;
                        IF NEW.main_count < 0 OR NEW.main_count > 255 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'main_count must be from 0 through 255'; END IF;
                        IF NEW.optional_count < 0 OR NEW.optional_count > 255 THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'optional_count must be from 0 through 255'; END IF;
                    END");
            }

            return;
        }
    }

    private function dropCapacityIntegrityTriggers(string $driver): void
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();

        foreach (['insert', 'update'] as $suffix) {
            $trigger = $grammar->wrap("monthly_shift_capacities_validate_{$suffix}");
            $connection->statement("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
