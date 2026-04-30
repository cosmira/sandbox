<?php

declare(strict_types=1);

namespace Packages\Sandbox\Commands;

use Illuminate\Console\Command;
use Packages\Sandbox\Sandbox;

class OpenSandboxCommand extends Command
{
    protected $signature = 'sandbox:open {userId? : The ID or UUID of the user (uses current user if omitted)} {--force : Force open even if locked by another user} {--note= : Optional note for the operation}';

    protected $description = 'Open a sandbox session for a user';

    /**
     * Выполнить команду.
     *
     * @param Sandbox $sandbox
     *
     * @return int
     */
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

        $force = $this->option('force') ?? false;
        $note = $this->option('note');

        try {
            $sandbox->open($userId, $force, $note);
            $this->info("Sandbox opened for user: {$userId}");

            return self::SUCCESS;
        } catch (\Exception $e) {
            $this->error("Failed to open sandbox: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
