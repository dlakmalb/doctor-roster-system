<?php

namespace App\Models;

use App\Enums\RosterAssignmentRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property RosterAssignmentRole $role */
#[Fillable(['roster_shift_id', 'doctor_id', 'role', 'slot_number'])]
class RosterAssignment extends Model
{
    protected function casts(): array
    {
        return [
            'role' => RosterAssignmentRole::class,
            'slot_number' => 'integer',
        ];
    }

    /** @return BelongsTo<RosterShift, $this> */
    public function rosterShift(): BelongsTo
    {
        return $this->belongsTo(RosterShift::class);
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** @return HasMany<ActualWorkException, $this> */
    public function actualWorkExceptions(): HasMany
    {
        return $this->hasMany(ActualWorkException::class, 'planned_assignment_id');
    }
}
