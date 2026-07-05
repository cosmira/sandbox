<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Commands;

use Cosmira\Sandbox\HasSandbox;

use function DragonCode\Benchmark\bench;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BenchmarkSyncCommand extends Command
{
    /**
     * The console command name and signature.
     *
     * @var string
     */
    protected $signature = 'sandbox:benchmark {--count=10000 : Number of records to synchronize}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Benchmark Sandbox synchronization performance';

    /**
     * The number of records to seed for each benchmark run.
     */
    protected int $recordCount;

    /**
     * The active table used by the benchmark.
     */
    protected string $activeTable = 'benchmark_items';

    /**
     * The sandbox table used by the benchmark.
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
                'Active > Sandbox' => $this->benchmarkActiveToSandbox(...),
                'Sandbox > Active' => $this->benchmarkSandboxToActive(...),
            ])
            ->toConsole();

        $this->teardownTables();

        $this->info("\nBenchmark completed!");

        return self::SUCCESS;
    }

    /**
     * Render the benchmark heading.
     */
    protected function renderHeader(): void
    {
        $this->info('======================================');
        $this->info("Sandbox Sync Benchmark ({$this->recordCount} records)");
        $this->info("======================================\n");
    }

    /**
     * Create the benchmark tables.
     */
    protected function setupTables(): void
    {
        Schema::dropIfExists($this->sandboxTable);
        Schema::dropIfExists($this->activeTable);

        Schema::create($this->activeTable, fn ($table) => $this->defineSchema($table));
        Schema::create($this->sandboxTable, fn ($table) => $this->defineSchema($table));
    }

    /**
     * Define the benchmark table schema.
     *
     * @param Blueprint $table
     */
    protected function defineSchema($table): void
    {
        $table->id();
        $table->string('name');
        $table->integer('value')->default(0);
        $table->timestamps();
    }

    /**
     * Insert active-table benchmark records.
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
     * Insert sandbox-table benchmark records.
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
     * Insert records into the given table in one statement.
     *
     * @param array<int, array<string, mixed>> $data
     */
    protected function bulkInsert(string $table, array $data): void
    {
        if (! empty($data)) {
            DB::table($table)->insert($data);
        }
    }

    /**
     * Benchmark syncing active records into the sandbox table.
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
     * Benchmark syncing sandbox records into the active table.
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
     * Truncate both benchmark tables.
     */
    protected function refreshTables(): void
    {
        DB::table($this->activeTable)->delete();
        DB::table($this->sandboxTable)->delete();
    }

    /**
     * Drop the benchmark tables.
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
     *
     * @var string
     */
    protected $table = 'benchmark_items';

    /**
     * The attributes that are not mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Get the column used to compare changes during sandbox sync.
     */
    protected static function getSandboxTrackChangeColumn(): ?string
    {
        return null;
    }

    /**
     * Get the columns copied by benchmark sync operations.
     *
     * @return array<int, string>
     */
    protected function getSandboxSyncColumns(): array
    {
        return ['id', 'name', 'value', 'created_at', 'updated_at'];
    }
}
