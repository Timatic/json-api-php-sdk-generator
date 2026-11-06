<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators;

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
use Spatie\LaravelData\Data;
use Timatic\JsonApiSdk\Generators\TestGenerators\Traits\DtoHelperTrait;

class JsonApiRequestGenerator extends RequestGenerator
{
    use DtoHelperTrait;

    protected ApiSpecification $specification;

    protected function foundationClass(string $relativePath): string
    {
        return $this->config->namespace.'\\Foundation\\'.$relativePath;
    }

    public function generate(ApiSpecification $specification): PhpFile|array
    {
        $this->specification = $specification;

        return parent::generate($specification);
    }

    protected function shouldIncludeEndpoint(Endpoint $endpoint): bool
    {
        if ($endpoint->method->isPut()) {
            return ! ($this->config->extra['excludePut'] ?? false);
        }

        return true;
    }

    protected function getRequestClassName(Endpoint $endpoint): string
    {
        $className = parent::getRequestClassName($endpoint);

        if ($this->isCollectionRequest($endpoint)) {
            $className .= 'Collection';
            $className = str_replace('Index', '', $className);
        }

        if (! str_ends_with($className, 'Request')) {
            $className .= 'Request';
        }

        return $className;
    }

    protected function customizeRequestClass(ClassType $classType, $namespace, Endpoint $endpoint): void
    {
        $hasFiltersClass = $this->foundationClass('Requests\\Concerns\\HasFilters');
        $hasIncludesClass = $this->foundationClass('Requests\\Concerns\\HasIncludes');

        if ($this->isCollectionRequest($endpoint)) {
            $namespace->addUse(Paginatable::class);
            $classType->addImplement(Paginatable::class);

            if ($this->hasFilterParameters($endpoint)) {
                $namespace->addUse($hasFiltersClass);
                $classType->addTrait($hasFiltersClass);
            }

            if ($this->hasIncludeParameter($endpoint)) {
                $namespace->addUse($hasIncludesClass);
                $classType->addTrait($hasIncludesClass);

                $this->addIncludeMethods($classType, $namespace, $endpoint);
            }
        }
    }

    protected function addRequestBodyParameter(Endpoint $endpoint, $namespace, $classConstructor, $classType)
    {
        $dtoType = $this->getRequestBodyDtoType($endpoint);

        $namespace->addUse($dtoType);

        $dataParam = new Parameter(
            type: '\\'.$dtoType,
            nullable: true,
            name: 'data',
            description: 'Request data',
        );

        MethodGeneratorHelper::addParameterAsPromotedProperty($classConstructor, $dataParam);

        $serializerClass = $this->foundationClass('JsonApi\\JsonApiSerializer');
        $namespace->addUse($serializerClass);

        $classType->addMethod('defaultBody')
            ->setProtected()
            ->setReturnType('array')
            ->addBody("return \$this->data ? ['data' => JsonApiSerializer::toJsonApi(\$this->data)] : [];");
    }

    protected function shouldIncludeQueryParameter(string $paramName): bool
    {
        if (str_starts_with($paramName, 'filter')) {
            return false;
        }

        if ($paramName === 'include') {
            return false;
        }

        return true;
    }

    protected function generateDefaultQueryMethod(ClassType $classType, $namespace, array $queryParams, Endpoint $endpoint): void
    {
        if (! empty($queryParams)) {
            parent::generateDefaultQueryMethod($classType, $namespace, $queryParams, $endpoint);
        }
    }

    protected function isMutationRequest(Endpoint $endpoint): bool
    {
        return $endpoint->method->isPost()
            || $endpoint->method->isPatch();
    }

    protected function isCollectionRequest(Endpoint $endpoint): bool
    {
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

    protected function hasIncludeParameter(Endpoint $endpoint): bool
    {
        foreach ($endpoint->queryParameters as $param) {
            if ($param->name === 'include') {
                return true;
            }
        }

        return false;
    }

    protected function shouldHaveHydration(Endpoint $endpoint): bool
    {
        $schemas = array_keys($this->specification->components->schemas);
        $dtoName = $this->getDtoClassName($endpoint);

        if (! in_array($dtoName, $schemas)) {
            return false;
        }

        return $endpoint->method->isGet()
            || $endpoint->method->isPost()
            || $endpoint->method->isPatch();
    }

    protected function addHydrationSupport(ClassType $classType, $namespace, Endpoint $endpoint): void
    {
        $dtoClassName = $this->getDtoClassName($endpoint);
        $transformerClass = $this->foundationClass('JsonApi\\JsonApiTransformer');

        $namespace->addUse($transformerClass);
        $namespace->addUse(Response::class);
        $namespace->addUse("{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$dtoClassName}");

        $method = $classType->addMethod('createDtoFromResponse')
            ->setReturnType('mixed');

        $param = $method->addParameter('response');
        $param->setType(Response::class);

        if ($this->isCollectionRequest($endpoint)) {
            $method->addBody('$flattened = JsonApiTransformer::flattenCollection(');
            $method->addBody("    {$dtoClassName}::class,");
            $method->addBody('    $response->json(\'data\') ?? [],');
            $method->addBody('    $response->json(\'included\') ?? []');
            $method->addBody(');');
            $method->addBody('');
            $method->addBody("return collect(\$flattened)->map(fn (array \$flat) => {$dtoClassName}::from(\$flat));");
        } else {
            $method->addBody('return '.$dtoClassName.'::from(JsonApiTransformer::flatten(');
            $method->addBody("    {$dtoClassName}::class,");
            $method->addBody('    $response->json(\'data\') ?? [],');
            $method->addBody('    $response->json(\'included\') ?? []');
            $method->addBody('));');
        }
    }

    protected function addIncludeMethods(ClassType $classType, $namespace, Endpoint $endpoint): void
    {
        $dtoClassName = $this->getDtoClassName($endpoint);

        if (! isset($this->specification->components->schemas[$dtoClassName])) {
            return;
        }

        $schema = $this->specification->components->schemas[$dtoClassName];

        if (! isset($schema->properties['relationships'])) {
            return;
        }

        $relationships = $schema->properties['relationships'];

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

    protected function getRequestBodyDtoType(Endpoint $endpoint): string
    {
        $fallback = Data::class;

        if (! $endpoint->requestBodySchema) {
            return $fallback;
        }

        $writeDtoClassName = $this->getWriteDtoClassName($endpoint);

        if ($writeDtoClassName) {
            return "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$writeDtoClassName}";
        }

        $schemaName = $endpoint->requestBodySchema;

        if (! isset($this->specification->components->schemas[$schemaName])) {
            return $fallback;
        }

        if (str_ends_with($schemaName, 'Identifier')) {
            return $fallback;
        }

        return "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$schemaName}";
    }
}
