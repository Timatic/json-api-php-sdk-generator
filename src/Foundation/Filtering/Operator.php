<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Foundation\Filtering;

enum Operator: string
{
    case Equals = 'eq';

    case NotEquals = 'nq';

    case GreaterThan = 'gt';

    case GreaterThanOrEquals = 'gte';

    case LessThan = 'lt';

    case LessThanOrEquals = 'lte';

    case Contains = 'contains';
}
