<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $start_time
 * @property string $end_time
 * @property int $duration_minutes
 * @property int $main_count
 * @property int $optional_count
 * @property bool $is_overnight
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
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

    /** @return HasMany<DoctorMonthlyWeekdayPreference, $this> */
    public function monthlyWeekdayPreferences(): HasMany
    {
        return $this->hasMany(DoctorMonthlyWeekdayPreference::class);
    }
}
