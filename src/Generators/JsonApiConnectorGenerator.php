<?php

declare(strict_types=1);

namespace JsonApiSdk\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Generators\ConnectorGenerator;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Saloon\Http\Request;
use Saloon\PaginationPlugin\Contracts\HasPagination;
use Saloon\Traits\Plugins\AlwaysThrowOnErrors;

class JsonApiConnectorGenerator extends ConnectorGenerator
{
    /**
     * Derive config key from connector name (e.g., "MyApiConnector" -> "myapi")
     */
    protected function getConfigKey(): string
    {
        $connectorName = $this->config->connectorName;
        // Remove "Connector" suffix and convert to lowercase
        return strtolower(preg_replace('/Connector$/', '', $connectorName));
    }

    protected function generateConnectorClass(ApiSpecification $specification): ?PhpFile
    {
        // Generate base connector using parent
        $phpFile = parent::generateConnectorClass($specification);

        // Get the namespace and class
        $namespace = array_values($phpFile->getNamespaces())[0];
        /** @var ClassType $classType */
        $classType = array_values($namespace->getClasses())[0];

        // Add HasPagination interface
        $namespace->addUse(HasPagination::class);
        $classType->addImplement(HasPagination::class);

        // Add AlwaysThrowOnErrors trait
        $namespace->addUse(AlwaysThrowOnErrors::class);
        $classType->addTrait(AlwaysThrowOnErrors::class);

        // Build Foundation class names with target namespace
        $foundationNamespace = $this->config->namespace . '\\Foundation';
        $jsonApiPaginatorClass = $foundationNamespace . '\\Pagination\\JsonApiPaginator';
        $jsonApiResponseClass = $foundationNamespace . '\\Responses\\JsonApiResponse';

        // Add additional imports for custom methods
        $namespace->addUse(Request::class);
        $namespace->addUse($jsonApiPaginatorClass);
        $namespace->addUse($jsonApiResponseClass);

        $configKey = $this->getConfigKey();

        // Override constructor to use property promotion (ServiceProvider injects the token)
        if ($classType->hasMethod('__construct')) {
            $constructor = $classType->getMethod('__construct');
            // Clear existing parameters
            foreach ($constructor->getParameters() as $param) {
                $constructor->removeParameter($param->getName());
            }
            // Add promoted property with nullable default
            $constructor->addPromotedParameter('bearerToken')
                ->setProtected()
                ->setType('?string')
                ->setDefaultValue(null);
            $constructor->setBody('');
        }

        // Override resolveBaseUrl to use Laravel config
        $resolveBaseUrl = $classType->getMethod('resolveBaseUrl');
        $resolveBaseUrl->setBody("return config('{$configKey}.base_url');");

        // Remove resource methods (we don't generate Resource classes)
        $knownMethods = ['__construct', 'resolveBaseUrl', 'defaultAuth', 'defaultConfig'];
        foreach ($classType->getMethods() as $methodName => $method) {
            if (!in_array($methodName, $knownMethods, true)) {
            $classType->removeMethod($methodName);
        }
        }

        // Add defaultHeaders method
        $this->addDefaultHeaders($classType);

        // Add resolveResponseClass method
        $this->addResolveResponseClassMethod($classType);

        // Add paginate method
        $this->addPaginateMethod($classType);

        // Re-add resource methods after custom configuration methods
        foreach ($resourceMethods as $methodName => $method) {
            $classType->setMethods(array_merge($classType->getMethods(), [$methodName => $method]));
        }

        return $phpFile;
    }

    public function removeEmptyConstructorIfPresent(ClassType $classType): void
    {
        if ($classType->hasMethod('__construct')) {
            $constructor = $classType->getMethod('__construct');
            // Only remove if it's empty (no parameters and no body)
            if (count($constructor->getParameters()) === 0 && empty(trim($constructor->getBody()))) {
                $classType->removeMethod('__construct');
            }
        }
    }

    public function addDefaultHeaders(ClassType $classType): void
    {
        $defaultHeaders = $classType->addMethod('defaultHeaders')
            ->setProtected()
            ->setReturnType('array');

        $defaultHeaders->setBody(<<<'PHP'
return [
    'Accept' => 'application/vnd.api+json',
    'Content-Type' => 'application/vnd.api+json',
];
PHP);
    }


    public function addPaginateMethod(ClassType $classType): void
    {
        $jsonApiPaginatorClass = $this->config->namespace . '\\Foundation\\Pagination\\JsonApiPaginator';

        $paginate = $classType->addMethod('paginate')
            ->setPublic()
            ->setReturnType($jsonApiPaginatorClass);

        $paginate->addParameter('request')
            ->setType(Request::class);

        $paginate->setBody('return new ?($this, $request);', [new Literal('JsonApiPaginator')]);
    }

    public function addResolveResponseClassMethod(ClassType $classType): void
    {
        $classType->addMethod('resolveResponseClass')
            ->setPublic()
            ->setReturnType('string')
            ->setBody('return ?;', [new Literal('JsonApiResponse::class')]);
    }
}
