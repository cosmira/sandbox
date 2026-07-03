<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    public function up(): void
    {
        $table = config('sandbox.table', 'sandbox_status');

        if (! Schema::hasTable($table) || Schema::hasColumn($table, 'id')) {
            return;
        }

        $status = DB::table($table)
            ->orderByDesc('change_date')
            ->first();

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->integer('id')->default(1);
        });

        DB::transaction(function () use ($table, $status): void {
            $attributes = $status === null
                ? [
                    'status'         => 0,
                    'last_operation' => null,
                    'note'           => null,
                    'change_date'    => now(),
                    'user_id'        => null,
                    'change_id'      => 0,
                    'send_date'      => null,
                ]
                : (array) $status;

            $attributes['id'] = 1;

            DB::table($table)->delete();
            DB::table($table)->insert($attributes);
        });

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->unique('id', 'sandbox_status_singleton_id_unique');
        });
    }

    public function down(): void
    {
        $table = config('sandbox.table', 'sandbox_status');

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'id')) {
            return;
        }

        if (! Schema::hasIndex($table, 'sandbox_status_singleton_id_unique')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->dropUnique('sandbox_status_singleton_id_unique');
        });

        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('id'));
    }
};
