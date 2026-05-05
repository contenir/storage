<?php

declare(strict_types=1);

namespace Contenir\Storage;

enum SortDirection: string
{
    case Asc  = 'asc';
    case Desc = 'desc';
}
