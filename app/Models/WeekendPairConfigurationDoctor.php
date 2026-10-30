<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WeekendPairConfigurationDoctor extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Pair configuration positions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Pair configuration positions are immutable.'));
    }
}
