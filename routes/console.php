<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('migrate:sqlite-to-mysql {--force} {--tables=*}', function () {
    $default = DB::getDefaultConnection();
    if ($default !== 'mysql') {
        $this->error("Default DB connection is '$default'. Please set DB_CONNECTION=mysql in your .env and try again.");
        return 1;
    }

    $sqliteConn = 'sqlite_old';
    try {
        DB::connection($sqliteConn)->getPdo();
    } catch (\Throwable $e) {
        $this->error("Cannot connect to '$sqliteConn': " . $e->getMessage());
        $this->line("Ensure SQLITE_OLD_DATABASE in .env points to your old SQLite file (e.g., database/database.sqlite).");
        return 1;
    }

    $tablesOption = (array) $this->option('tables');

    // Fetch list of tables from SQLite
    $tables = collect(DB::connection($sqliteConn)->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'"))
        ->pluck('name')
        ->reject(function ($t) { return in_array($t, ['migrations']); });

    if (!empty($tablesOption)) {
        $tables = $tables->intersect($tablesOption);
    }

    if ($tables->isEmpty()) {
        $this->warn('No tables found to migrate.');
        return 0;
    }

    $this->info('Tables to migrate: ' . $tables->implode(', '));

    if (!$this->option('force') && !$this->confirm('This will TRUNCATE the corresponding MySQL tables before copying. Continue?')) {
        $this->warn('Aborted.');
        return 1;
    }

    // Disable FK checks in MySQL to avoid constraint issues during bulk copy
    DB::statement('SET FOREIGN_KEY_CHECKS=0');

    $totalInserted = 0;
    foreach ($tables as $table) {
        if (!Schema::hasTable($table)) {
            $this->warn("[Skip] MySQL table '$table' does not exist. Run migrations first.");
            continue;
        }

        $this->line("Processing table: $table");
        // Truncate MySQL table
        DB::table($table)->truncate();

        // Read from SQLite in chunks
        $count = DB::connection($sqliteConn)->table($table)->count();
        $inserted = 0;
        $bar = $this->output->createProgressBar($count ?: 1);
        $bar->start();

        DB::connection($sqliteConn)->table($table)->orderBy('rowid')->chunk(500, function ($rows) use ($table, &$inserted, $bar) {
            $batch = [];
            foreach ($rows as $row) {
                $batch[] = (array) $row; // preserve IDs and columns as-is
            }
            if (!empty($batch)) {
                DB::table($table)->insert($batch);
                $inserted += count($batch);
                $bar->advance(count($batch));
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info("Inserted $inserted/$count rows into '$table'.");
        $totalInserted += $inserted;
    }

    // Re-enable FK checks
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    $this->info("Done. Total rows inserted: $totalInserted");
    $this->comment('Tip: Run php artisan migrate --force before this command to ensure schema exists in MySQL.');

    return 0;
})->purpose('Copy data from the old SQLite database into the current MySQL database (one-time migration).');
