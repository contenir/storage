<?php

declare(strict_types=1);

namespace Contenir\Storage;

enum SortField: string
{
    case Name = 'name';
    case Time = 'time';
    case Size = 'size';
    case Type = 'type';
}
