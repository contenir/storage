<?php

declare(strict_types=1);

namespace Contenir\Storage;

enum VariantFit: string
{
    case Cover   = 'cover';
    case Contain = 'contain';
    case Fill    = 'fill';
}
