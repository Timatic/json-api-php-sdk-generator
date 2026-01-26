<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators;

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;

class ServiceProviderGenerator
{
    public function __construct(
        private string $namespace,
        private string $configKey,
        private string $connectorName,
    ) {}

    public function generate(): PhpFile
    {
        $file = new PhpFile();
        $file->setStrictTypes();

        $namespace = $file->addNamespace($this->namespace . '\\Providers');
        $namespace->addUse('Illuminate\\Support\\ServiceProvider');
        $namespace->addUse($this->namespace . '\\' . $this->connectorName);

        $appName = $this->getAppName();
        $class = $namespace->addClass($appName . 'ServiceProvider');
        $class->setExtends('Illuminate\\Support\\ServiceProvider');

        $this->addRegisterMethod($class);
        $this->addBootMethod($class);

        return $file;
    }

    private function getAppName(): string
    {
        return preg_replace('/Connector$/', '', $this->connectorName);
    }

    private function addRegisterMethod(ClassType $class): void
    {
        $appName = $this->getAppName();

        $method = $class->addMethod('register')
            ->setPublic()
            ->setReturnType('void');

        $method->addComment('Register services.');

        $body = <<<PHP
// Merge config
\$this->mergeConfigFrom(
    __DIR__.'/../../config/{$this->configKey}.php',
    '{$this->configKey}'
);

// Register {$this->connectorName} as singleton
\$this->app->singleton({$appName}Connector::class, fn () => new {$appName}Connector(
    config('{$this->configKey}.api_token'),
));

// Register alias
\$this->app->alias({$appName}Connector::class, '{$this->configKey}');
PHP;

        $method->setBody($body);
    }

    private function addBootMethod(ClassType $class): void
    {
        $method = $class->addMethod('boot')
            ->setPublic()
            ->setReturnType('void');

        $method->addComment('Bootstrap services.');

        $body = <<<PHP
// Publish config
\$this->publishes([
    __DIR__.'/../../config/{$this->configKey}.php' => config_path('{$this->configKey}.php'),
], '{$this->configKey}-config');
PHP;

        $method->setBody($body);
    }

    public function getClassName(): string
    {
        return $this->getAppName() . 'ServiceProvider';
    }
}
