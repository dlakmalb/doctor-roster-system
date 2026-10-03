<?php

namespace App\Models;

use App\Enums\ActualWorkExceptionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['roster_shift_id', 'planned_assignment_id', 'exception_type', 'actual_doctor_id', 'note', 'recorded_by'])]
class ActualWorkException extends Model
{
    protected function casts(): array
    {
        return [
            'exception_type' => ActualWorkExceptionType::class,
        ];
    }

    /** @return BelongsTo<RosterShift, $this> */
    public function rosterShift(): BelongsTo
    {
        return $this->belongsTo(RosterShift::class);
    }

    /** @return BelongsTo<RosterAssignment, $this> */
    public function plannedAssignment(): BelongsTo
    {
        return $this->belongsTo(RosterAssignment::class, 'planned_assignment_id');
    }

    /** @return BelongsTo<Doctor, $this> */
    public function actualDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'actual_doctor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
