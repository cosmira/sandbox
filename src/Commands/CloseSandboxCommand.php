<?php

declare(strict_types=1);

namespace Packages\Sandbox\Commands;

use Illuminate\Console\Command;
use Packages\Sandbox\Sandbox;

class CloseSandboxCommand extends Command
{
    protected $signature = 'sandbox:close {userId? : The ID or UUID of the user (uses current user if omitted)} {--result=0 : Result code: 0=rollback, 1=commit, 2=save without commit} {--note= : Optional note for the operation} {--async : Use async updater}';

    protected $description = 'Close a sandbox session';

    public function handle(Sandbox $sandbox): int
    {
        $userId = $this->argument('userId');

        if (! $userId) {
            $user = auth()->user();
            if (! $user) {
                $this->error('No user specified and no authenticated user found');

                return self::FAILURE;
            }
            $userId = $user->getAuthIdentifier();
        }

        $result = (int) ($this->option('result') ?? 0);
        $note = $this->option('note');
        $asyncUpdater = (bool) $this->option('async');

        if (! in_array($result, [0, 1, 2], true)) {
            $this->error('Result code must be 0 (rollback), 1 (commit), or 2 (save)');

            return self::FAILURE;
        }

        try {
            $sandbox->close($userId, $result, $note, $asyncUpdater);

            $resultText = match ($result) {
                0 => 'rollback',
                1 => 'commit',
                2 => 'save',
            };

            $this->info("Sandbox closed with result: {$resultText}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to close sandbox: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
