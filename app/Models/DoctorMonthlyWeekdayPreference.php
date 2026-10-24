<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $doctor_id
 * @property int $year
 * @property int $month
 * @property int $shift_type_id
 * @property int $weekday ISO weekday number from 1 (Monday) to 7 (Sunday)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Doctor $doctor
 * @property-read ShiftType $shiftType
 */
#[Fillable(['doctor_id', 'year', 'month', 'shift_type_id', 'weekday'])]
class DoctorMonthlyWeekdayPreference extends Model
{
    protected function casts(): array
    {
        return ['year' => 'integer', 'month' => 'integer', 'weekday' => 'integer'];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** @return BelongsTo<ShiftType, $this> */
    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class);
    }
}
