<?php

declare(strict_types=1);

namespace Cosmira\Sandbox\Commands;

use Cosmira\Sandbox\HasSandbox;
use Illuminate\Database\Eloquent\Model;

final class BenchmarkItem extends Model
{
    use HasSandbox;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'benchmark_items';

    /**
     * The attributes that are not mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

    /**
     * Indicates if the IDs are auto-incrementing.
     *
     * @var bool
     */
    public $incrementing = true;

    /**
     * Get the column used to compare changes during sandbox sync.
     */
    protected static function getSandboxTrackChangeColumn(): null
    {
        return null;
    }

    /**
     * Get the columns copied by benchmark sync operations.
     *
     * @return array<int, string>
     */
    protected function getSandboxSyncColumns(): array
    {
        return ['id', 'name', 'value', 'created_at', 'updated_at'];
    }
}
