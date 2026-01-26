<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk;

use Timatic\JsonApiSdk\Commands\GenerateCommand;
use Symfony\Component\Console\Application as BaseApplication;

class Application extends BaseApplication
{
    public function __construct()
    {
        parent::__construct('JSON:API SDK Generator', '1.0.0');

        $this->add(new GenerateCommand());
    }
}
