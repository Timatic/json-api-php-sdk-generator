<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Foundation\Hydration;

enum RelationType
{
    case Many;
    case One;
}
