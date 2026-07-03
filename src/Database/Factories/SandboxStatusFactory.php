<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Database\Factories;

use Cosmira\Sandbox\Enums\SandboxStatus as SandboxStatusEnum;
use Cosmira\Sandbox\Models\SandboxStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Factory for sandbox status rows.
 *
 * @extends Factory<SandboxStatus>
 */
class SandboxStatusFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<SandboxStatus>
     */
    protected $model = SandboxStatus::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id'             => 1,
            'status'         => SandboxStatusEnum::Free,
            'last_operation' => null,
            'note'           => null,
            'change_date'    => now(),
            'user_id'        => null,
            'change_id'      => 0,
            'send_date'      => null,
        ];
    }
}
