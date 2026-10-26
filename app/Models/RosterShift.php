<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property int $id
 * @property int $roster_id
 * @property int $shift_type_id
 * @property Carbon $shift_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['roster_id', 'shift_type_id', 'shift_date'])]
class RosterShift extends Model
{
    protected function casts(): array
    {
        return [
            'shift_date' => 'date',
        ];
    }

    /** @return BelongsTo<Roster, $this> */
    public function roster(): BelongsTo
    {
        return $this->belongsTo(Roster::class);
    }

    /** @return BelongsTo<ShiftType, $this> */
    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class);
    }

    /** @return HasOne<RosterShiftCapacitySnapshot, $this> */
    public function capacitySnapshot(): HasOne
    {
        return $this->hasOne(RosterShiftCapacitySnapshot::class);
    }

    public function savedCapacity(): RosterShiftCapacitySnapshot
    {
        $snapshot = $this->relationLoaded('capacitySnapshot')
            ? $this->getRelation('capacitySnapshot')
            : $this->capacitySnapshot()->first();

        if (! $snapshot instanceof RosterShiftCapacitySnapshot) {
            throw new LogicException("Roster shift {$this->id} is missing its capacity snapshot.");
        }

        return $snapshot;
    }

    /** @return HasMany<RosterAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(RosterAssignment::class);
    }

    /** @return HasMany<ActualWorkException, $this> */
    public function actualWorkExceptions(): HasMany
    {
        return $this->hasMany(ActualWorkException::class);
    }
}
