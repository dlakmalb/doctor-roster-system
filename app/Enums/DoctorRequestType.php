<?php

namespace App\Enums;

enum DoctorRequestType: string
{
    case DayOff = 'day_off';
    case PreferredWork = 'preferred_work';
}
