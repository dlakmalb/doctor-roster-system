<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use LogicException;

/**
 * @property int $id
 * @property int $roster_shift_id
 * @property int $main_count
 * @property int $optional_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['roster_shift_id', 'main_count', 'optional_count'])]
class RosterShiftCapacitySnapshot extends Model
{
    protected function casts(): array
    {
        return [
            'main_count' => 'integer',
            'optional_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $snapshot): void {
            foreach (['main_count', 'optional_count'] as $attribute) {
                $value = $snapshot->getAttribute($attribute);
                if (! is_int($value) || $value < 0 || $value > 255) {
                    throw new InvalidArgumentException("The $attribute capacity must be an integer from 0 through 255.");
                }
            }
        });

        static::updating(function (self $snapshot): void {
            if ($snapshot->isDirty(['roster_shift_id', 'main_count', 'optional_count'])) {
                throw new LogicException('Roster shift capacity snapshots are immutable.');
            }
        });

        static::deleting(function (self $snapshot): void {
            if ($snapshot->rosterShift()->exists()) {
                throw new LogicException('Roster shift capacity snapshots cannot be deleted while their roster shift exists.');
            }
        });
    }

    /** @return BelongsTo<RosterShift, $this> */
    public function rosterShift(): BelongsTo
    {
        return $this->belongsTo(RosterShift::class);
    }
}
