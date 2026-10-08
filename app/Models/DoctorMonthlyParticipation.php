<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $doctor_id
 * @property int|null $roster_id
 * @property int $year
 * @property int $month
 * @property bool $is_participating
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['doctor_id', 'roster_id', 'year', 'month', 'is_participating'])]
class DoctorMonthlyParticipation extends Model
{
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'is_participating' => 'boolean',
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
