<?php

declare(strict_types=1);

namespace JsonApiSdk\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Generators\RequestGenerator;
use Crescat\SaloonSdkGenerator\Helpers\MethodGeneratorHelper;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Saloon\Http\Response;
use Saloon\PaginationPlugin\Contracts\Paginatable;
use JsonApiSdk\Generators\TestGenerators\Traits\DtoHelperTrait;

class JsonApiRequestGenerator extends RequestGenerator
{
    use DtoHelperTrait;

    private ApiSpecification $specification;

    /**
     * Get Foundation class with target namespace
     */
    protected function foundationClass(string $relativePath): string
    {
        return $this->config->namespace . '\\Foundation\\' . $relativePath;
    }

    public function generate(ApiSpecification $specification): PhpFile|array
    {
        $this->specification = $specification;

        return parent::generate($specification);
    }

    /**
     * Hook: Filter out PUT requests - not supported in JSON:API
     */
    protected function shouldIncludeEndpoint(Endpoint $endpoint): bool
    {
        return ! $endpoint->method->isPut();
    }

    /**
     * Hook: Add "Request" suffix to class names
     * For collection requests, add "Collection" before "Request"
     */
    protected function getRequestClassName(Endpoint $endpoint): string
    {
        $className = parent::getRequestClassName($endpoint);

        // For collection requests, add "Collection" suffix
        if ($this->isCollectionRequest($endpoint)) {
            $className .= 'Collection';
            $className = str_replace('Index', '', $className);
        }

        if (! str_ends_with($className, 'Request')) {
            $className .= 'Request';
        }

        return $className;
    }

    /**
     * Hook: Transform path parameter names (e.g., budget -> budgetId)
     */
    protected function getConstructorParameterName(string $originalName, bool $isPathParam = false): string
    {
        if ($isPathParam) {
            return $originalName.'Id';
        }

        return $originalName;
    }

    /**
     * Hook: Customize request class for collection requests
     */
    protected function customizeRequestClass(ClassType $classType, $namespace, Endpoint $endpoint): void
    {
        // Get Foundation classes with target namespace
        $hasFiltersClass = $this->foundationClass('Requests\\Concerns\\HasFilters');
        $hasIncludesClass = $this->foundationClass('Requests\\Concerns\\HasIncludes');

        if ($this->isCollectionRequest($endpoint)) {
            // Add Paginatable interface to all collection requests
            $namespace->addUse(Paginatable::class);
            $classType->addImplement(Paginatable::class);

            // Add HasFilters trait if collection has filter parameters in the endpoint
            if ($this->hasFilterParameters($endpoint)) {
                $namespace->addUse($hasFiltersClass);
                $classType->addTrait($hasFiltersClass);
            }

            // Add HasIncludes trait if endpoint has include parameter
            if ($this->hasIncludeParameter($endpoint)) {
                $namespace->addUse($hasIncludesClass);
                $classType->addTrait($hasIncludesClass);

                // Add relationship-specific include methods
                $this->addIncludeMethods($classType, $namespace, $endpoint);
            }
        }

        // Add hydration support to GET, POST, and PATCH requests
        if ($this->shouldHaveHydration($endpoint)) {
            $this->addHydrationSupport($classType, $namespace, $endpoint);
        }
    }

    /**
     * Hook: Customize constructor for mutation requests
     */
    protected function customizeConstructor($classConstructor, ClassType $classType, $namespace, Endpoint $endpoint): void
    {
        if (! $this->isMutationRequest($endpoint)) {
            return;
        }

        $modelClass = $this->foundationClass('Hydration\\Model');
        $namespace->addUse($modelClass);

        $dataParam = new Parameter(
            type: '\\' . $modelClass . '|array|null',
            nullable: true,
            name: 'data',
            description: 'Request data',
        );

        MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $dataParam);

        $classType->addMethod('defaultBody')
            ->setProtected()
            ->setReturnType('array')
            ->addBody("return \$this->data ? ['data' => \$this->data->toJsonApi()] : [];");
    }

    /**
     * Hook: Filter out filter* and include query parameters (handled by traits)
     */
    protected function shouldIncludeQueryParameter(string $paramName): bool
    {
        // Filter out filter* parameters (handled by HasFilters trait)
        if (str_starts_with($paramName, 'filter')) {
            return false;
        }

        // Filter out include parameter (handled by HasIncludes trait)
        if ($paramName === 'include') {
            return false;
        }

        return true;
    }

    /**
     * Hook: Generate defaultQuery method with custom JSON:API logic
     */
    protected function generateDefaultQueryMethod(\Nette\PhpGenerator\ClassType $classType, $namespace, array $queryParams, Endpoint $endpoint): void
    {

        // For other cases with query parameters, use parent implementation
        if (! empty($queryParams)) {
            parent::generateDefaultQueryMethod($classType, $namespace, $queryParams, $endpoint);
        }
    }

    // Helper methods for JSON:API logic

    protected function isMutationRequest(Endpoint $endpoint): bool
    {
        // Only POST and PATCH are supported mutation methods
        return $endpoint->method->isPost()
            || $endpoint->method->isPatch();
    }

    protected function isCollectionRequest(Endpoint $endpoint): bool
    {
        // Collection requests are GET requests without path parameters
        return $endpoint->method->isGet() && empty($endpoint->pathParameters);
    }

    protected function hasFilterParameters(Endpoint $endpoint): bool
    {
        foreach ($endpoint->queryParameters as $param) {
            if (str_starts_with($param->name, 'filter')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if endpoint has include query parameter
     */
    protected function hasIncludeParameter(Endpoint $endpoint): bool
    {
        foreach ($endpoint->queryParameters as $param) {
            if ($param->name === 'include') {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if request should have hydration support
     */
    protected function shouldHaveHydration(Endpoint $endpoint): bool
    {
        $schemas = array_keys($this->specification->components->schemas);
        $dtoName = $this->getDtoClassName($endpoint);

        if (! in_array($dtoName, $schemas)) {
            // There is no schema, so also no DTO to hydrate
            return false;
        }

        // Add hydration to GET, POST, and PATCH requests
        return $endpoint->method->isGet()
            || $endpoint->method->isPost()
            || $endpoint->method->isPatch();
    }

    /**
     * Add hydration support to request class
     */
    protected function addHydrationSupport(ClassType $classType, $namespace, Endpoint $endpoint): void
    {
        // Determine DTO class name from endpoint
        $dtoClassName = $this->getDtoClassName($endpoint);

        // Get Foundation classes with target namespace
        $hydratorClass = $this->foundationClass('Hydration\\Facades\\Hydrator');

        // Add imports
        $namespace->addUse($hydratorClass);
        $namespace->addUse(Response::class);
        $namespace->addUse("{$this->config->namespace}\\Dto\\{$dtoClassName}");

        // Add $model property - use the imported class name with ::class
        $classType->addProperty('model')
            ->setProtected()
            ->setValue(new \Nette\PhpGenerator\Literal("{$dtoClassName}::class"));

        // Add createDtoFromResponse method
        $method = $classType->addMethod('createDtoFromResponse')
            ->setReturnType('mixed');

        $param = $method->addParameter('response');
        $param->setType(Response::class);

        // Use appropriate hydration method based on request type
        if ($this->isCollectionRequest($endpoint)) {
            // Collection: use hydrateCollection
            $method->addBody('return Hydrator::hydrateCollection(');
            $method->addBody('    $this->model,');
            $method->addBody('    $response->json(\'data\'),');
            $method->addBody('    $response->json(\'included\')');
            $method->addBody(');');
        } else {
            // Single resource: use hydrate
            $method->addBody('return Hydrator::hydrate(');
            $method->addBody('    $this->model,');
            $method->addBody('    $response->json(\'data\'),');
            $method->addBody('    $response->json(\'included\')');
            $method->addBody(');');
        }
    }

    /**
     * Add relationship-specific include methods to request class
     */
    protected function addIncludeMethods(ClassType $classType, $namespace, Endpoint $endpoint): void
    {
        // Get the DTO class name for this endpoint
        $dtoClassName = $this->getDtoClassName($endpoint);

        // Check if schema exists in specification
        if (! isset($this->specification->components->schemas[$dtoClassName])) {
            return;
        }

        $schema = $this->specification->components->schemas[$dtoClassName];

        // Check if schema has relationships
        if (! isset($schema->properties['relationships'])) {
            return;
        }

        $relationships = $schema->properties['relationships'];

        // Generate include method for each relationship
        if (isset($relationships->properties) && is_array($relationships->properties)) {
            foreach ($relationships->properties as $relationName => $relationSpec) {
                $methodName = 'include'.Str::studly($relationName);

                $classType->addMethod($methodName)
                    ->setPublic()
                    ->setReturnType('static')
                    ->addComment("Include the {$relationName} relationship in the response")
                    ->addBody("return \$this->addInclude('{$relationName}');");
            }
        }
    }
}
