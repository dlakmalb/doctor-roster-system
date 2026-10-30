<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

class WeekendPairCoordination
{
    public function lock(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Weekend pair coordination requires a transaction.');
        }

        $rows = DB::table('weekend_pair_coordination')->where('id', 1)->update(['id' => DB::raw('id')]);
        if ($rows !== 1 && ! DB::table('weekend_pair_coordination')->where('id', 1)->exists()) {
            throw new LogicException('The weekend pair coordination row is missing.');
        }
    }
}
