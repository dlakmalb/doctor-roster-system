<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property Carbon $anchor_saturday
 * @property string $anchor_group
 */
#[Fillable(['singleton_key', 'anchor_saturday', 'anchor_group'])]
class WeekendRotationConfiguration extends Model
{
    protected function casts(): array
    {
        return ['anchor_saturday' => 'date'];
    }
}
