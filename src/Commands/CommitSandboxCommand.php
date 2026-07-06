<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Commands;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Sandbox;
use Illuminate\Console\Command;

class CommitSandboxCommand extends Command
{
    /**
     * The console command name and signature.
     *
     * @var string
     */
    protected $signature = 'sandbox:commit'
        .' {userId? : The ID or UUID of the user (uses current user if omitted)}'
        .' {--note= : Optional note for the operation}'
        .' {--async : Use async updater}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Commit the sandbox draft to active data';

    /**
     * Execute the console command.
     */
    public function handle(Sandbox $sandbox): int
    {
        $userId = $this->userId();

        if ($userId === null) {
            return self::FAILURE;
        }

        try {
            $sandbox->commit(
                $userId,
                $this->option('note'),
                $this->option('async') === true,
            );

            $this->info('Sandbox committed');

            return self::SUCCESS;
        } catch (SandboxException $e) {
            $this->error("Failed to commit sandbox: {$e->getMessage()}");

            return self::FAILURE;
        }
    }

    /**
     * Resolve the user ID for the command.
     */
    private function userId(): int|string|null
    {
        $userId = $this->argument('userId');

        if ($userId) {
            return $userId;
        }

        $user = auth()->user();
        if (! $user) {
            $this->error('No user specified and no authenticated user found');

            return null;
        }

        return $user->getAuthIdentifier();
    }
}
