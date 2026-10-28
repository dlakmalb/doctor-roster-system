<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeekendPairConfiguration extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Pair configuration versions are immutable.'));
        static::deleting(fn () => throw new \LogicException('Pair configuration versions are immutable.'));
    }

    protected function casts(): array
    {
        return ['effective_saturday' => 'immutable_date', 'pair_anchor_saturday' => 'immutable_date'];
    }

    public function doctors(): HasMany
    {
        return $this->hasMany(WeekendPairConfigurationDoctor::class)->orderBy('position');
    }
}
