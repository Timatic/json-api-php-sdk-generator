<?php

declare(strict_types=1);

namespace JsonApiSdk\Generators;

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;

class TestSetupGenerator
{
    public function __construct(
        private string $namespace,
        private string $configKey,
        private string $connectorName,
        private string $baseUrl,
    ) {}

    /**
     * Generate Pest.php content.
     */
    public function generatePestPhp(): string
    {
        return <<<PHP
<?php

use {$this->namespace}\\Tests\\TestCase;

uses(TestCase::class)->in(__DIR__);

PHP;
    }

    /**
     * Generate TestCase.php using Nette PhpGenerator.
     */
    public function generateTestCase(): PhpFile
    {
        $file = new PhpFile();
        $file->setStrictTypes();

        $namespace = $file->addNamespace($this->namespace . '\\Tests');
        $namespace->addUse('Dotenv\\Dotenv');
        $namespace->addUse('Orchestra\\Testbench\\TestCase', 'Orchestra');
        $namespace->addUse('Saloon\\Laravel\\SaloonServiceProvider');
        $namespace->addUse($this->namespace . '\\Providers\\' . $this->getAppName() . 'ServiceProvider');

        $class = $namespace->addClass('TestCase');
        $class->setExtends('Orchestra\\Testbench\\TestCase');

        $this->addGetPackageProvidersMethod($class);
        $this->addGetEnvironmentSetUpMethod($class);

        return $file;
    }

    private function getAppName(): string
    {
        return preg_replace('/Connector$/', '', $this->connectorName);
    }

    private function addGetPackageProvidersMethod(ClassType $class): void
    {
        $appName = $this->getAppName();

        $method = $class->addMethod('getPackageProviders')
            ->setProtected()
            ->setReturnType('array');

        $method->addParameter('app');

        $method->setBody(<<<PHP
return [
    SaloonServiceProvider::class,
    {$appName}ServiceProvider::class,
];
PHP);
    }

    private function addGetEnvironmentSetUpMethod(ClassType $class): void
    {
        $envVarPrefix = strtoupper($this->configKey);

        $method = $class->addMethod('getEnvironmentSetUp')
            ->setProtected()
            ->setReturnType('void');

        $method->addParameter('app');

        $method->setBody(<<<PHP
// Load .env into the environment if it exists
if (file_exists(dirname(__DIR__).'/.env')) {
    (Dotenv::createImmutable(dirname(__DIR__), '.env'))->load();
}

// Set config values for testing
\$app['config']->set('{$this->configKey}.base_url', env('{$envVarPrefix}_BASE_URL', '{$this->baseUrl}'));
\$app['config']->set('{$this->configKey}.api_token', env('{$envVarPrefix}_API_TOKEN'));
PHP);
    }
}
