<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $doctor_id
 * @property string|null $group_code
 * @property Carbon $effective_from_saturday
 */
#[Fillable(['doctor_id', 'group_code', 'effective_from_saturday', 'changed_by'])]
class DoctorWeekendGroupMembership extends Model
{
    protected function casts(): array
    {
        return ['effective_from_saturday' => 'date'];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
