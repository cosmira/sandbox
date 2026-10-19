<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Commands;

use Cosmira\Sandbox\Enums\SandboxOperation;
use Cosmira\Sandbox\Sandbox;
use Illuminate\Console\Command;

class StatusSandboxCommand extends Command
{
    /**
     * Display value for missing status details.
     */
    private const NOT_AVAILABLE = 'N/A';

    /**
     * The console command name and signature.
     *
     * @var string
     */
    protected $signature = 'sandbox:status {--details : Show detailed information}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display current sandbox status';

    /**
     * Execute the console command.
     */
    public function handle(Sandbox $sandbox): int
    {
        $status = $sandbox->status();

        if (! $status) {
            $this->warn('Sandbox status not found in database');

            return self::FAILURE;
        }

        if ($status->isFree()) {
            $this->info('Sandbox is FREE (not in use)');
        }
        if ($status->isLocked()) {
            $this->info("Sandbox is LOCKED by user: {$status->user_id}");
        }
        if ($status->isSaved()) {
            $this->info("Sandbox is SAVED (user: {$status->user_id})");
        }

        if ($this->option('details')) {
            $this->line('Detailed Information:');
            $this->table(['Key', 'Value'], [
                ['Status Code', $status->status->name],
                ['User ID', $status->user_id ?? self::NOT_AVAILABLE],
                ['Last Operation', $this->getOperationName($status->last_operation)],
                ['Changed At', $status->change_date->format('Y-m-d H:i:s')],
                ['Sent At', $status->send_date?->format('Y-m-d H:i:s') ?? self::NOT_AVAILABLE],
                ['Note', $status->note ?? self::NOT_AVAILABLE],
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Get the display name for a sandbox operation.
     */
    private function getOperationName(?SandboxOperation $operation): string
    {
        return $operation?->description() ?? self::NOT_AVAILABLE;
    }
}
