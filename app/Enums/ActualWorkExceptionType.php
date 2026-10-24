<?php

namespace App\Enums;

enum ActualWorkExceptionType: string
{
    case Replacement = 'replacement';
    case OptionalWorked = 'optional_worked';
    case MainAbsent = 'main_absent';
}
