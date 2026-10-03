<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['roster_id', 'shift_type_id', 'shift_date'])]
class RosterShift extends Model
{
    protected function casts(): array
    {
        return [
            'shift_date' => 'date',
        ];
    }

    /** @return BelongsTo<Roster, $this> */
    public function roster(): BelongsTo
    {
        return $this->belongsTo(Roster::class);
    }

    /** @return BelongsTo<ShiftType, $this> */
    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class);
    }

    /** @return HasMany<RosterAssignment, $this> */
    public function assignments(): HasMany
    {
        return $this->hasMany(RosterAssignment::class);
    }

    /** @return HasMany<ActualWorkException, $this> */
    public function actualWorkExceptions(): HasMany
    {
        return $this->hasMany(ActualWorkException::class);
    }
}
