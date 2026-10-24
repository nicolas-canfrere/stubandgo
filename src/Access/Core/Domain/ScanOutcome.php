<?php

declare(strict_types=1);

namespace App\Access\Core\Domain;

enum ScanOutcome: string
{
    case Valid = 'valid';
    case AlreadyUsed = 'already_used';
    case WrongEvent = 'wrong_event';
    case Unknown = 'unknown';
}
