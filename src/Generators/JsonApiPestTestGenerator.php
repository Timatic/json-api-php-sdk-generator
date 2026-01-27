<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
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

    protected CollectionRequestTestGenerator $collectionTestGenerator;

    protected MutationRequestTestGenerator $mutationTestGenerator;

    protected SingularGetRequestTestGenerator $singularGetTestGenerator;

    protected DeleteRequestTestGenerator $deleteTestGenerator;

    protected GeneratedCode $generatedCode;

    /**
     * Override process() to instantiate test generators with ApiSpecification and GeneratedCode
     */
    public function process(
        Config $config,
        ApiSpecification $specification,
        GeneratedCode $generatedCode,
    ): PhpFile|array|null {
        // Store generated code for later use
        $this->generatedCode = $generatedCode;

        // Instantiate test generators with the parsed ApiSpecification, GeneratedCode, and namespace
        $namespace = $config->namespace;
        $this->collectionTestGenerator = new CollectionRequestTestGenerator($specification, $generatedCode, $namespace);
        $this->mutationTestGenerator = new MutationRequestTestGenerator($specification, $generatedCode, $namespace);
        $this->singularGetTestGenerator = new SingularGetRequestTestGenerator($specification, $generatedCode, $namespace);
        $this->deleteTestGenerator = new DeleteRequestTestGenerator($specification, $generatedCode, $namespace);

        // Call parent to continue normal processing
        return parent::process($config, $specification, $generatedCode);
    }

    /**
     * Skip generating Pest.php - we have a custom version
     */
    protected function shouldGeneratePestFile(): bool
    {
        return false;
    }

    /**
     * Skip generating TestCase.php - we have a custom version
     */
    protected function shouldGenerateTestCaseFile(): bool
    {
        return false;
    }

    /**
     * Filter out PUT requests and endpoints without DTOs
     */
    protected function shouldIncludeEndpoint(Endpoint $endpoint): bool
    {
        if ($endpoint->method->isPut()) {
            $excludePut = $this->config->extra['excludePut'] ?? false;
            if ($excludePut) {
                echo "  ⊘ Skipping PUT: {$endpoint->name}\n";
                return false;
            }
        }

        // Skip endpoints without DTOs (endpoints that don't return data)
        if (! $this->hasDtoForEndpoint($endpoint)) {
            return false;
        }

        // Skip endpoints where DTO has no properties (will fail during mock data generation)
        if (! $this->hasValidDtoProperties($endpoint)) {
            return false;
        }

        return true;
    }

    /**
     * Check if endpoint has a DTO with valid properties for test generation
     */
    protected function hasValidDtoProperties(Endpoint $endpoint): bool
    {
        try {
            // Try to generate mock data - will throw if DTO has no properties
            if ($this->collectionTestGenerator->isCollectionRequest($endpoint)) {
                $this->collectionTestGenerator->generateMockData($endpoint);
            } elseif ($this->singularGetTestGenerator->isSingularGetRequest($endpoint)) {
                $this->singularGetTestGenerator->generateMockData($endpoint);
            }

            return true;
        } catch (\RuntimeException $e) {
            // DTO has no properties - skip test generation
            if (str_contains($e->getMessage(), 'has no properties')) {
                echo "  ⊘ Skipping {$endpoint->name}: ".$e->getMessage()."\n";

                return false;
            }
            // Re-throw other exceptions
            throw $e;
        }
    }

    /**
     * Check if a DTO exists for the endpoint
     */
    protected function hasDtoForEndpoint(Endpoint $endpoint): bool
    {
        $dtoClassName = $this->getDtoClassName($endpoint);

        // Check if this DTO was generated in the current run
        return array_key_exists($dtoClassName, $this->generatedCode->dtoClasses);
    }

    /**
     * Use our custom JSON:API test stub
     */
    protected function getTestStubPath(): string
    {
        return __DIR__.'/TestGenerators/stubs/pest-resource-test.stub';
    }

    /**
     * Use our custom JSON:API test function stub
     */
    protected function getTestFunctionStubPath(Endpoint $endpoint): string
    {
        // Delegate to CollectionRequestTestGenerator for collection requests
        if ($this->collectionTestGenerator->isCollectionRequest($endpoint)) {
            return $this->collectionTestGenerator->getStubPath();
        }

        // Delegate to MutationRequestTestGenerator for mutation requests
        if ($this->mutationTestGenerator->isMutationRequest($endpoint)) {
            return $this->mutationTestGenerator->getStubPath();
        }

        // Delegate to SingularGetRequestTestGenerator for singular GET requests
        if ($this->singularGetTestGenerator->isSingularGetRequest($endpoint)) {
            return $this->singularGetTestGenerator->getStubPath();
        }

        // Delegate to DeleteRequestTestGenerator for DELETE requests
        if ($this->deleteTestGenerator->isDeleteRequest($endpoint)) {
            return $this->deleteTestGenerator->getStubPath();
        }

        throw new \RuntimeException('Unmatched request type');
    }

    /**
     * Add "Request" suffix to match JsonApiRequestGenerator behavior
     * For collection requests, add "Collection" before "Request"
     */
    protected function getRequestClassName(Endpoint $endpoint): string
    {
        // Use inline collection detection (can't access JsonApiRequestGenerator's method)
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
        // Strip "Request" suffix if present to match actual Resource method names
        $methodBaseName = str_ends_with($requestClassName, 'Request')
            ? substr($requestClassName, 0, -7)
            : $requestClassName;

        return NameHelper::safeVariableName($methodBaseName);
    }

    /**
     * Place tests in tests/Requests/ directory
     */
    protected function getTestPath(string $resourceName): string
    {
        return "tests/Requests/{$resourceName}Test.php";
    }

    /**
     * Hook: Transform path parameter names (e.g., budget -> budgetId)
     */
    protected function getTestParameterName(Parameter $parameter, Endpoint $endpoint): string
    {
        $name = parent::getTestParameterName($parameter, $endpoint);

        // Check if this is a path parameter
        if (in_array($parameter, $endpoint->pathParameters, true)) {
            // Only append 'Id' if the parameter doesn't already end with 'Id' or 'ID'
            if (!preg_match('/Id$/i', $name)) {
                return $name.'Id';
            }
        }

        return $name;
    }

    /**
     * Hook: Replace additional stub variables
     */
    protected function replaceAdditionalStubVariables(
        string $functionStub,
        Endpoint $endpoint,
        string $resourceName,
        string $requestClassName
    ): string {
        // Delegate to CollectionRequestTestGenerator for collection requests
        if ($this->collectionTestGenerator->isCollectionRequest($endpoint)) {
            return $this->collectionTestGenerator->replaceStubVariables($functionStub, $endpoint);
        }

        // Delegate to MutationRequestTestGenerator for mutation requests
        if ($this->mutationTestGenerator->isMutationRequest($endpoint)) {
            return $this->mutationTestGenerator->replaceStubVariables($functionStub, $endpoint);
        }

        // Delegate to SingularGetRequestTestGenerator for singular GET requests
        if ($this->singularGetTestGenerator->isSingularGetRequest($endpoint)) {
            return $this->singularGetTestGenerator->replaceStubVariables($functionStub, $endpoint);
        }

        // Delegate to DeleteRequestTestGenerator for DELETE requests
        if ($this->deleteTestGenerator->isDeleteRequest($endpoint)) {
            return $this->deleteTestGenerator->replaceStubVariables($functionStub, $endpoint);
        }

        throw new \RuntimeException('Unmatched request type');
    }
}
