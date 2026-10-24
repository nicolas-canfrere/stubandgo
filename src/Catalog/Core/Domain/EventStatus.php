<?php

declare(strict_types=1);

namespace App\Catalog\Core\Domain;

enum EventStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
