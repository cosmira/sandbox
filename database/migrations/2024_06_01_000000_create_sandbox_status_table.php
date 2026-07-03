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

        if (Schema::hasTable($table)) {
            return;
        }

        Schema::create($table, function (Blueprint $blueprint): void {
            $blueprint->integer('id')->primary();
            $blueprint->unsignedTinyInteger('status')->default(0);
            $blueprint->unsignedTinyInteger('last_operation')->nullable();
            $blueprint->string('note', 500)->nullable();
            $blueprint->dateTime('change_date');
            $blueprint->string('user_id', 255)->nullable();
            $blueprint->unsignedInteger('change_id')->default(0);
            $blueprint->dateTime('send_date')->nullable();
        });

        DB::table($table)->insert([
            'id'             => 1,
            'status'         => 0,
            'last_operation' => null,
            'note'           => null,
            'change_date'    => now(),
            'user_id'        => null,
            'change_id'      => 0,
            'send_date'      => null,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists(config('sandbox.table', 'sandbox_status'));
    }
};
