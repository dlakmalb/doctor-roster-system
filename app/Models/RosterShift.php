<?php

namespace App\Models;

use App\Enums\RosterAssignmentRole;
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
        if ((int) $snapshot->roster_shift_id !== (int) $this->id
            || $snapshot->main_count < 0 || $snapshot->main_count > 255
            || $snapshot->optional_count < 0 || $snapshot->optional_count > 255) {
            throw new LogicException("Roster shift {$this->id} has an inconsistent capacity snapshot.");
        }

        return $snapshot;
    }

    public function savedCapacityForRole(RosterAssignmentRole $role): int
    {
        $capacity = $this->savedCapacity();

        return $role === RosterAssignmentRole::Main ? $capacity->main_count : $capacity->optional_count;
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
