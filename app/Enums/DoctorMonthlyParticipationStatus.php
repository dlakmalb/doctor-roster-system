<?php

namespace App\Enums;

enum DoctorMonthlyParticipationStatus: string
{
    case Participating = 'participating';
    case FullMonthExcluded = 'full_month_excluded';
    case NotPartOfTeam = 'not_part_of_team';
}
