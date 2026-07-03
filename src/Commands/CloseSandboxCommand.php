<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Commands;

use Cosmira\Sandbox\Enums\SandboxOperation;
use Cosmira\Sandbox\Exceptions\SandboxException;
use Cosmira\Sandbox\Sandbox;
use Illuminate\Console\Command;

class CloseSandboxCommand extends Command
{
    /**
     * The console command name and signature.
     *
     * @var string
     */
    protected $signature = 'sandbox:close'
        .' {userId? : The ID or UUID of the user (uses current user if omitted)}'
        .' {--result=rollback : Result operation: rollback, commit, or save}'
        .' {--note= : Optional note for the operation}'
        .' {--async : Use async updater}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Close a sandbox session';

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

        $result = SandboxOperation::tryFromInput(
            (string) ($this->option('result') ?? SandboxOperation::Rollback->label()),
        );
        $note = $this->option('note');
        $asyncUpdater = (bool) $this->option('async');

        if (! $result instanceof SandboxOperation) {
            $this->error('Result must be rollback, commit, or save');

            return self::FAILURE;
        }

        try {
            $sandbox->close($userId, $result, $note, $asyncUpdater);

            $this->info("Sandbox closed with result: {$result->label()}");

            return self::SUCCESS;
        } catch (SandboxException $e) {
            $this->error("Failed to close sandbox: {$e->getMessage()}");

            return self::FAILURE;
        }
    }
}
