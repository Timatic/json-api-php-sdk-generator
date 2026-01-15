<?php

declare(strict_types=1);

namespace JsonApiSdk\Foundation\Hydration;

enum RelationType
{
    case Many;
    case One;
}
