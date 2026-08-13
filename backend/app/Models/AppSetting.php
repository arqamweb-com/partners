<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** صف واحد فقط (id = 1). */
class AppSetting extends Model
{
    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['stage_defaults' => 'array'];
    }

    public static function current(): self
    {
        return static::firstOrCreate(['id' => 1]);
    }
}
