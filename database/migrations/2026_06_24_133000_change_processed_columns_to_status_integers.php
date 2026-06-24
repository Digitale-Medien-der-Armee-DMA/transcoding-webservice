<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChangeProcessedColumnsToStatusIntegers extends Migration
{
    private const UNPROCESSED = 0;
    private const PROCESSED = 1;
    private const PROCESSING = 2;
    private const FAILED = 3;

    public function up()
    {
        $this->normaliseStatusValues('downloads');
        $this->normaliseStatusValues('videos');
        $this->changeProcessedColumnType('downloads', 'up');
        $this->changeProcessedColumnType('videos', 'up');
    }

    public function down()
    {
        $this->normaliseBooleanRollbackValues('downloads');
        $this->normaliseBooleanRollbackValues('videos');
        $this->changeProcessedColumnType('downloads', 'down');
        $this->changeProcessedColumnType('videos', 'down');
    }

    private function normaliseStatusValues(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'processed')) {
            return;
        }

        DB::table($table)->whereNull('processed')->update(['processed' => self::UNPROCESSED]);
        DB::table($table)
            ->whereNotIn('processed', [
                self::UNPROCESSED,
                self::PROCESSED,
                self::PROCESSING,
                self::FAILED,
            ])
            ->update(['processed' => self::UNPROCESSED]);
    }

    private function normaliseBooleanRollbackValues(string $table): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'processed')) {
            return;
        }

        DB::table($table)
            ->whereIn('processed', [self::PROCESSING, self::FAILED])
            ->update(['processed' => self::PROCESSED]);
    }

    private function changeProcessedColumnType(string $table, string $direction): void
    {
        if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'processed')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $type = $direction === 'up' ? 'TINYINT UNSIGNED' : 'TINYINT(1)';
            DB::statement("ALTER TABLE `{$table}` MODIFY `processed` {$type} NOT NULL DEFAULT 0");
            return;
        }

        if ($driver === 'pgsql') {
            if ($direction === 'up') {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN processed TYPE SMALLINT USING processed::smallint");
                DB::statement("ALTER TABLE {$table} ALTER COLUMN processed SET DEFAULT 0");
            } else {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN processed TYPE BOOLEAN USING processed::boolean");
                DB::statement("ALTER TABLE {$table} ALTER COLUMN processed SET DEFAULT false");
            }
            return;
        }

        // SQLite stores boolean values as integers already. CI uses SQLite, so
        // value-level tests cover the application contract without DBAL change().
        if ($driver === 'sqlite') {
            return;
        }
    }
}
