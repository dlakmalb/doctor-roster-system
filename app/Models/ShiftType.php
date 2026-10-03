<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['code', 'name', 'start_time', 'end_time', 'duration_minutes', 'main_count', 'optional_count', 'is_overnight', 'is_active'])]
class ShiftType extends Model
{
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'main_count' => 'integer',
            'optional_count' => 'integer',
            'is_overnight' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<RosterShift, $this> */
    public function rosterShifts(): HasMany
    {
        return $this->hasMany(RosterShift::class);
    }

    /** @return HasMany<DoctorRequest, $this> */
    public function doctorRequests(): HasMany
    {
        return $this->hasMany(DoctorRequest::class);
    }
}
