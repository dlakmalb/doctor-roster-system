<?php

namespace App\Models;

use App\Enums\DoctorMonthlyWorkloadSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property DoctorMonthlyWorkloadSource $source
 * @property Carbon|null $most_recent_night_shift_at
 */
#[Fillable(['doctor_id', 'roster_id', 'year', 'month', 'source', 'actual_worked_minutes', 'opening_balance_minutes', 'monthly_adjustment_minutes', 'closing_balance_minutes', 'actual_night_duty_count', 'optional_assignment_count', 'worked_final_weekend', 'most_recent_night_shift_at', 'is_month_excluded'])]
class DoctorMonthlyWorkload extends Model
{
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'source' => DoctorMonthlyWorkloadSource::class,
            'actual_worked_minutes' => 'integer',
            'opening_balance_minutes' => 'integer',
            'monthly_adjustment_minutes' => 'integer',
            'closing_balance_minutes' => 'integer',
            'actual_night_duty_count' => 'integer',
            'optional_assignment_count' => 'integer',
            'worked_final_weekend' => 'boolean',
            'most_recent_night_shift_at' => 'datetime',
            'is_month_excluded' => 'boolean',
        ];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** @return BelongsTo<Roster, $this> */
    public function roster(): BelongsTo
    {
        return $this->belongsTo(Roster::class);
    }
}
