<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Generators\PestTestGenerator;
use Crescat\SaloonSdkGenerator\Helpers\DtoResolver;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Timatic\JsonApiSdk\Generators\TestGenerators\CollectionRequestTestGenerator;
use Timatic\JsonApiSdk\Generators\TestGenerators\DeleteRequestTestGenerator;
use Timatic\JsonApiSdk\Generators\TestGenerators\MutationRequestTestGenerator;
use Timatic\JsonApiSdk\Generators\TestGenerators\SingularGetRequestTestGenerator;
use Timatic\JsonApiSdk\Generators\TestGenerators\Traits\DtoHelperTrait;

class JsonApiPestTestGenerator extends PestTestGenerator
{
    use DtoHelperTrait;

    protected function initializeTestGenerators(
        ApiSpecification $specification,
        GeneratedCode $generatedCode,
        string $namespace,
        DtoResolver $dtoResolver,
    ): void {
        $this->collectionTestGenerator = new CollectionRequestTestGenerator($specification, $generatedCode, $namespace, $dtoResolver);
        $this->mutationTestGenerator = new MutationRequestTestGenerator($specification, $generatedCode, $namespace, $dtoResolver);
        $this->singularGetTestGenerator = new SingularGetRequestTestGenerator($specification, $generatedCode, $namespace, $dtoResolver);
        $this->deleteTestGenerator = new DeleteRequestTestGenerator($specification, $generatedCode, $namespace, $dtoResolver);
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
            if ($this->collectionTestGenerator->isApplicable($endpoint)) {
                $this->collectionTestGenerator->generateMockData($endpoint);
            } elseif ($this->singularGetTestGenerator->isApplicable($endpoint)) {
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
}
