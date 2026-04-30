<?php

declare(strict_types=1);

namespace Packages\Sandbox\Commands;

use Illuminate\Console\Command;
use Packages\Sandbox\Sandbox;

class StatusSandboxCommand extends Command
{
    protected $signature = 'sandbox:status {--details : Show detailed information}';

    protected $description = 'Display current sandbox status';

    /**
     * Выполнить команду.
     *
     * @param Sandbox $sandbox
     *
     * @return int
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
        } elseif ($status->isLocked()) {
            $this->info("Sandbox is LOCKED by user: {$status->user_id}");
        } elseif ($status->isSaved()) {
            $this->info("Sandbox is SAVED (user: {$status->user_id})");
        }

        if ($this->option('details')) {
            $this->line('');
            $this->line('Detailed Information:');
            $this->table(['Key', 'Value'], [
                ['Status Code', $status->status->name],
                ['User ID', $status->user_id ?? 'N/A'],
                ['Last Operation', $this->getOperationName($status->last_operation)],
                ['Changed At', $status->change_date?->format('Y-m-d H:i:s') ?? 'N/A'],
                ['Sent At', $status->send_date?->format('Y-m-d H:i:s') ?? 'N/A'],
                ['Note', $status->note ?? 'N/A'],
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Получить человеческое имя операции по коду.
     *
     * @param int|null $operation
     *
     * @return string
     */
    private function getOperationName(?int $operation): string
    {
        return match ($operation) {
            0       => 'Rollback',
            1       => 'Commit',
            2       => 'Save without commit',
            null    => 'N/A',
            default => "Unknown ({$operation})",
        };
    }
}
