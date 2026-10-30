<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonthlyWeekendPairConfirmation extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Monthly pair confirmations are immutable.'));
        static::deleting(fn () => throw new \LogicException('Monthly pair confirmations are immutable.'));
    }
}
