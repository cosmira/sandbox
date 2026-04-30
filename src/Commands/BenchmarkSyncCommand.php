<?php

declare(strict_types=1);

namespace Packages\Sandbox\Commands;

use function DragonCode\Benchmark\bench;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Packages\Sandbox\HasSandbox;

class BenchmarkSyncCommand extends Command
{
    /**
     * The console command signature.
     */
    protected $signature = 'sandbox:benchmark {--count=10000 : Number of records to synchronize}';

    /**
     * The console command description.
     */
    protected $description = 'Benchmark Sandbox synchronization performance';

    /**
     * Number of records used in benchmark.
     */
    protected int $recordCount;

    /**
     * Active table name.
     */
    protected string $activeTable = 'benchmark_items';

    /**
     * Sandbox table name.
     */
    protected string $sandboxTable = 'benchmark_items_sb';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->recordCount = (int) $this->option('count');

        $this->renderHeader();

        $this->setupTables();

        bench()
            ->round(2)
            ->compare([
                'Active > Sandbox' => fn () => $this->benchmarkActiveToSandbox(),
                'Sandbox > Active' => fn () => $this->benchmarkSandboxToActive(),
            ])
            ->toConsole();

        $this->teardownTables();

        $this->info("\nBenchmark completed!");

        return self::SUCCESS;
    }

    /**
     * Display benchmark header.
     */
    protected function renderHeader(): void
    {
        $this->info('======================================');
        $this->info("Sandbox Sync Benchmark ({$this->recordCount} records)");
        $this->info("======================================\n");
    }

    /**
     * Create fresh tables for benchmark.
     */
    protected function setupTables(): void
    {
        Schema::dropIfExists($this->sandboxTable);
        Schema::dropIfExists($this->activeTable);

        Schema::create($this->activeTable, fn ($table) => $this->defineSchema($table));
        Schema::create($this->sandboxTable, fn ($table) => $this->defineSchema($table));
    }

    /**
     * Define table structure.
     */
    protected function defineSchema($table): void
    {
        $table->id();
        $table->string('name');
        $table->integer('value')->default(0);
        $table->timestamps();
    }

    /**
     * Insert test data into table.
     */
    protected function insertTestData(?string $table = null): void
    {
        $table ??= $this->activeTable;

        $data = [];
        $now = now()->toDateTimeString();

        for ($i = 1; $i <= $this->recordCount; $i++) {
            $data[] = [
                'name'       => "item_{$i}",
                'value'      => $i * 10,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($i % 10_000 === 0) {
                $this->bulkInsert($table, $data);
                $data = [];
            }
        }

        $this->bulkInsert($table, $data);
    }

    /**
     * Insert sandbox-specific test data.
     */
    protected function insertSandboxTestData(string $table): void
    {
        $data = [];
        $now = now()->toDateTimeString();
        $limit = (int) ($this->recordCount / 2);

        for ($i = 1; $i <= $limit; $i++) {
            $data[] = [
                'name'       => "sandbox_item_{$i}",
                'value'      => $i * 20,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($i % 10_000 === 0) {
                $this->bulkInsert($table, $data);
                $data = [];
            }
        }

        $this->bulkInsert($table, $data);
    }

    /**
     * Perform bulk insert if data exists.
     */
    protected function bulkInsert(string $table, array $data): void
    {
        if (! empty($data)) {
            DB::table($table)->insert($data);
        }
    }

    /**
     * Benchmark: Active → Sandbox sync.
     */
    protected function benchmarkActiveToSandbox(): void
    {
        $this->refreshTables();

        $this->insertTestData($this->activeTable);
        $this->insertSandboxTestData($this->sandboxTable);

        BenchmarkItem::syncIntoSandbox();

        $this->refreshTables();
    }

    /**
     * Benchmark: Sandbox → Active sync.
     */
    protected function benchmarkSandboxToActive(): void
    {
        $this->refreshTables();

        $this->insertTestData($this->activeTable);
        $this->insertSandboxTestData($this->sandboxTable);

        BenchmarkItem::syncIntoActive();

        $this->refreshTables();
    }

    /**
     * Truncate both tables.
     */
    protected function refreshTables(): void
    {
        DB::table($this->activeTable)->truncate();
        DB::table($this->sandboxTable)->truncate();
    }

    /**
     * Drop benchmark tables.
     */
    protected function teardownTables(): void
    {
        Schema::dropIfExists($this->sandboxTable);
        Schema::dropIfExists($this->activeTable);
    }
}

class BenchmarkItem extends Model
{
    use HasSandbox;

    /**
     * The table associated with the model.
     */
    protected $table = 'benchmark_items';

    /**
     * The attributes that aren't mass assignable.
     */
    protected $guarded = [];

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = true;

    /**
     * Indicates if the IDs are auto-incrementing.
     */
    public $incrementing = true;

    /**
     * Column used to track changes in sandbox.
     */
    protected static function getSandboxTrackChangeColumn(): ?string
    {
        return null;
    }

    /**
     * Columns used for synchronization.
     */
    protected function getSandboxSyncColumns(): array
    {
        return ['id', 'name', 'value', 'created_at', 'updated_at'];
    }
}
