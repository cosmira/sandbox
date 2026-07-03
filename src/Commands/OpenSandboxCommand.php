<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Commands;

use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Sandbox;
use Illuminate\Console\Command;

class OpenSandboxCommand extends Command
{
    /**
     * The console command name and signature.
     *
     * @var string
     */
    protected $signature = 'sandbox:open'
        .' {userId? : The ID or UUID of the user (uses current user if omitted)}'
        .' {--force : Force open even if locked by another user}'
        .' {--note= : Optional note for the operation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Open a sandbox session for a user';

    /**
     * Execute the console command.
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

        $force = (bool) $this->option('force');
        $note = $this->option('note');

        try {
            $sandbox->open($userId, $force, $note);
            $this->info("Sandbox opened for user: {$userId}");

            return self::SUCCESS;
        } catch (SandboxException $e) {
            $this->error("Failed to open sandbox: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
