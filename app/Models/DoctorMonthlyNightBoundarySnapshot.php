<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/** @property Carbon $night_date */
#[Fillable(['year', 'month', 'night_date'])]
class DoctorMonthlyNightBoundarySnapshot extends Model
{
    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'night_date' => 'date'];
    }

    /** @return BelongsToMany<Doctor, $this> */
    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'doctor_monthly_night_boundary_doctors', 'snapshot_id', 'doctor_id')->withTimestamps();
    }
}
