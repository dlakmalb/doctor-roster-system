<?php

namespace App\Models;

use App\Enums\RosterStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $year
 * @property int $month
 * @property RosterStatus $status
 * @property Carbon|null $last_generated_at
 * @property Carbon|null $actual_work_confirmed_at
 * @property Carbon|null $finalized_at
 * @property int|null $finalized_by
 * @property Carbon|null $reopened_at
 * @property int|null $reopened_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['year', 'month', 'status', 'last_generated_at', 'finalized_at', 'reopened_at', 'actual_work_confirmed_at', 'created_by', 'updated_by', 'finalized_by', 'reopened_by', 'actual_work_confirmed_by'])]
class Roster extends Model
{
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'status' => RosterStatus::class,
            'last_generated_at' => 'datetime',
            'finalized_at' => 'datetime',
            'reopened_at' => 'datetime',
            'actual_work_confirmed_at' => 'datetime',
        ];
    }

    /** @return HasMany<RosterShift, $this> */
    public function shifts(): HasMany
    {
        return $this->hasMany(RosterShift::class);
    }

    /** @return HasMany<DoctorMonthlyWorkload, $this> */
    public function monthlyWorkloads(): HasMany
    {
        return $this->hasMany(DoctorMonthlyWorkload::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return BelongsTo<User, $this> */
    public function finalizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    /** @return BelongsTo<User, $this> */
    public function actualWorkConfirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actual_work_confirmed_by');
    }
}
