<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Foundation\Hydration\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class DateTime
{
    public function __construct(
        public string $format = 'Y-m-d\TH:i:sP'
    ) {}
}
