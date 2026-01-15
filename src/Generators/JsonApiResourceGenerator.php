<?php

declare(strict_types=1);

namespace JsonApiSdk\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Generators\ResourceGenerator;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Nette\PhpGenerator\Method;
use JsonApiSdk\Foundation\Hydration\Model;

class JsonApiResourceGenerator extends ResourceGenerator
{
    /**
     * Hook: Filter out PUT requests - not supported in JSON:API
     */
    protected function shouldIncludeEndpoint(Endpoint $endpoint): bool
    {
        return ! $endpoint->method->isPut();
    }

    /**
     * Hook: Add "Request" suffix to request class names
     * For collection requests, add "Collection" before "Request"
     */
    protected function getRequestClassName(Endpoint $endpoint): string
    {
        // Use inline collection detection
        $isCollection = $endpoint->method->isGet() && empty($endpoint->pathParameters);

        $className = parent::getRequestClassName($endpoint);

        if ($isCollection) {
            $className .= 'Collection';
        }

        if (! str_ends_with($className, 'Request')) {
            $className .= 'Request';
        }

        return $className;
    }

    /**
     * Hook: Strip "Request" suffix from method names
     */
    protected function getMethodName(Endpoint $endpoint, string $requestClassName): string
    {
        // Strip "Request" suffix if present to get clean method names
        $methodBaseName = str_ends_with($requestClassName, 'Request')
            ? substr($requestClassName, 0, -7)
            : $requestClassName;

        return \Crescat\SaloonSdkGenerator\Helpers\NameHelper::safeVariableName($methodBaseName);
    }

    /**
     * Hook: Transform path parameter names (e.g., budget -> budgetId)
     */
    protected function getResourceParameterName(Parameter $parameter, bool $isPathParam): string
    {
        if ($isPathParam) {
            return $parameter->name.'Id';
        }

        return $parameter->name;
    }

    /**
     * Hook: Customize resource method for mutation requests
     */
    protected function customizeResourceMethod(\Nette\PhpGenerator\Method $method, $namespace, array &$args, Endpoint $endpoint): void
    {
        if (! ($endpoint->method->isPost() || $endpoint->method->isPatch())) {
            return;
        }

        $namespace->addUse(Model::class);

        $dataParam = new Parameter(
            type: Model::class.'|array|null',
            nullable: true,
            name: 'data',
            description: 'Request data',
        );

        $this->addPropertyToMethod($method, $dataParam);
        $args[] = new \Nette\PhpGenerator\Literal('$data');
    }

    protected function addPropertyToMethod(Method $method, Parameter $parameter): Method
    {
        $name = NameHelper::safeVariableName($parameter->name);

        if (str_contains($parameter->name, 'filter')) {
            return $method;
        }

        $param = $method
            ->addParameter($name)
            ->setType($parameter->type)
            ->setNullable($parameter->nullable);

        if ($parameter->nullable) {
            $param->setDefaultValue(null);
        }

        return $method;
    }
}
