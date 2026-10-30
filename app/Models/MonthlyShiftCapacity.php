<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * @property int $id
 * @property int $year
 * @property int $month
 * @property int $shift_type_id
 * @property int $main_count
 * @property int $optional_count
 */
#[Fillable(['year', 'month', 'shift_type_id', 'main_count', 'optional_count'])]
class MonthlyShiftCapacity extends Model
{
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'shift_type_id' => 'integer',
            'main_count' => 'integer',
            'optional_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $capacity): void {
            $validator = Validator::make($capacity->getAttributes(), [
                'year' => ['required', 'integer', 'between:1,65535'],
                'month' => ['required', 'integer', 'between:1,12'],
                'shift_type_id' => ['required', 'integer', 'min:1'],
                'main_count' => ['required', 'integer', 'between:0,255'],
                'optional_count' => ['required', 'integer', 'between:0,255'],
            ]);

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
        });
    }

    /** @return BelongsTo<ShiftType, $this> */
    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class);
    }
}
