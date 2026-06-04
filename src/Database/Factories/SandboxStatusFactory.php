<?php

declare(strict_types=1);

namespace Packages\Sandbox\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Packages\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Packages\Sandbox\Models\SandboxStatus;

/**
 * @extends Factory<SandboxStatus>
 */
class SandboxStatusFactory extends Factory
{
    protected $model = SandboxStatus::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status'         => SandboxStatusEnum::Free,
            'last_operation' => null,
            'note'           => null,
            'change_date'    => now(),
            'user_id'        => 1,
            'change_id'      => 0,
            'send_date'      => now(),
        ];
    }
}
