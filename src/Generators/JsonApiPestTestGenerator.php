<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Generators\PestTestGenerator;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Nette\PhpGenerator\PhpFile;
use Timatic\JsonApiSdk\Generators\TestGenerators\CollectionRequestTestGenerator;
use Timatic\JsonApiSdk\Generators\TestGenerators\DeleteRequestTestGenerator;
use Timatic\JsonApiSdk\Generators\TestGenerators\MutationRequestTestGenerator;
use Timatic\JsonApiSdk\Generators\TestGenerators\SingularGetRequestTestGenerator;
use Timatic\JsonApiSdk\Generators\TestGenerators\Traits\DtoHelperTrait;

class JsonApiPestTestGenerator extends PestTestGenerator
{
    use DtoHelperTrait;

    public function process(
        Config $config,
        ApiSpecification $specification,
        GeneratedCode $generatedCode,
    ): PhpFile|array|null {
        $this->generatedCode = $generatedCode;

        $namespace = $config->namespace;
        $this->collectionTestGenerator = new CollectionRequestTestGenerator($specification, $generatedCode, $namespace);
        $this->mutationTestGenerator = new MutationRequestTestGenerator($specification, $generatedCode, $namespace);
        $this->singularGetTestGenerator = new SingularGetRequestTestGenerator($specification, $generatedCode, $namespace);
        $this->deleteTestGenerator = new DeleteRequestTestGenerator($specification, $generatedCode, $namespace);

        return parent::process($config, $specification, $generatedCode);
    }

    protected function shouldIncludeEndpoint(Endpoint $endpoint): bool
    {
        if ($endpoint->method->isPut()) {
            $excludePut = $this->config->extra['excludePut'] ?? false;
            if ($excludePut) {
                echo "  ⊘ Skipping PUT: {$endpoint->name}\n";

                return false;
            }
        }

        if (! $this->hasDtoForEndpoint($endpoint)) {
            return false;
        }

        if (! $this->hasValidDtoProperties($endpoint)) {
            return false;
        }

        return true;
    }

    protected function hasValidDtoProperties(Endpoint $endpoint): bool
    {
        try {
            if ($this->collectionTestGenerator->isCollectionRequest($endpoint)) {
                $this->collectionTestGenerator->generateMockData($endpoint);
            } elseif ($this->singularGetTestGenerator->isSingularGetRequest($endpoint)) {
                $this->singularGetTestGenerator->generateMockData($endpoint);
            }

            return true;
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'has no properties')) {
                echo "  ⊘ Skipping {$endpoint->name}: ".$e->getMessage()."\n";

                return false;
            }
            throw $e;
        }
    }

    protected function hasDtoForEndpoint(Endpoint $endpoint): bool
    {
        $dtoClassName = $this->getDtoClassName($endpoint);

        return array_key_exists($dtoClassName, $this->generatedCode->dtoClasses);
    }

    protected function getTestStubPath(): string
    {
        return __DIR__.'/TestGenerators/stubs/pest-resource-test.stub';
    }

    protected function getTestFunctionStubPath(Endpoint $endpoint): string
    {
        if ($this->collectionTestGenerator->isCollectionRequest($endpoint)) {
            return $this->collectionTestGenerator->getStubPath();
        }

        if ($this->mutationTestGenerator->isMutationRequest($endpoint)) {
            return $this->mutationTestGenerator->getStubPath();
        }

        if ($this->singularGetTestGenerator->isSingularGetRequest($endpoint)) {
            return $this->singularGetTestGenerator->getStubPath();
        }

        if ($this->deleteTestGenerator->isDeleteRequest($endpoint)) {
            return $this->deleteTestGenerator->getStubPath();
        }

        throw new \RuntimeException('Unmatched request type');
    }

    protected function getRequestClassName(Endpoint $endpoint): string
    {
        $isCollection = $endpoint->method->isGet() && empty($endpoint->pathParameters);

        $className = NameHelper::requestClassName($endpoint->name ?: NameHelper::pathBasedName($endpoint));

        if ($isCollection) {
            $className .= 'Collection';
            $className = str_replace('Index', '', $className);
        }

        if (! str_ends_with($className, 'Request')) {
            $className .= 'Request';
        }

        return $className;
    }

    protected function getMethodName(Endpoint $endpoint, string $requestClassName): string
    {
        $methodBaseName = str_ends_with($requestClassName, 'Request')
            ? substr($requestClassName, 0, -7)
            : $requestClassName;

        return NameHelper::safeVariableName($methodBaseName);
    }

    protected function getTestPath(string $resourceName): string
    {
        return "tests/Requests/{$resourceName}Test.php";
    }

    protected function replaceAdditionalStubVariables(
        string $functionStub,
        Endpoint $endpoint,
        string $resourceName,
        string $requestClassName,
    ): string {
        if ($this->collectionTestGenerator->isCollectionRequest($endpoint)) {
            return $this->collectionTestGenerator->replaceStubVariables($functionStub, $endpoint);
        }

        if ($this->mutationTestGenerator->isMutationRequest($endpoint)) {
            return $this->mutationTestGenerator->replaceStubVariables($functionStub, $endpoint);
        }

        if ($this->singularGetTestGenerator->isSingularGetRequest($endpoint)) {
            return $this->singularGetTestGenerator->replaceStubVariables($functionStub, $endpoint);
        }

        if ($this->deleteTestGenerator->isDeleteRequest($endpoint)) {
            return $this->deleteTestGenerator->replaceStubVariables($functionStub, $endpoint);
        }

        throw new \RuntimeException('Unmatched request type');
    }
}