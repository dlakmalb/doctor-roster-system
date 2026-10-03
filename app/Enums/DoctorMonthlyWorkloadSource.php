<?php

namespace App\Enums;

enum DoctorMonthlyWorkloadSource: string
{
    case System = 'system';
    case ManualInitial = 'manual_initial';
}
