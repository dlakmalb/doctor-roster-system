<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'short_code', 'is_active'])]
class Doctor extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<DoctorRequest, $this> */
    public function requests(): HasMany
    {
        return $this->hasMany(DoctorRequest::class);
    }

    /** @return HasMany<DoctorMonthlyExclusion, $this> */
    public function monthlyExclusions(): HasMany
    {
        return $this->hasMany(DoctorMonthlyExclusion::class);
    }

    /** @return HasMany<RosterAssignment, $this> */
    public function rosterAssignments(): HasMany
    {
        return $this->hasMany(RosterAssignment::class);
    }

    /** @return HasMany<DoctorMonthlyWorkload, $this> */
    public function monthlyWorkloads(): HasMany
    {
        return $this->hasMany(DoctorMonthlyWorkload::class);
    }

    /** @return HasMany<ActualWorkException, $this> */
    public function actualWorkExceptions(): HasMany
    {
        return $this->hasMany(ActualWorkException::class, 'actual_doctor_id');
    }
}
