<?php

namespace App\DataTransferObjects;

enum SelectorResolutionStatus
{
    case Found;
    case NotFound;
    case Ambiguous;
}
