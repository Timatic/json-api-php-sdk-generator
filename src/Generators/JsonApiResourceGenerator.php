<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\Endpoint;
use Crescat\SaloonSdkGenerator\Data\Generator\Parameter;
use Crescat\SaloonSdkGenerator\Generators\ResourceGenerator;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\Method;
use Spatie\LaravelData\Data;

class JsonApiResourceGenerator extends ResourceGenerator
{
    protected function foundationClass(string $relativePath): string
    {
        return $this->config->namespace.'\\Foundation\\'.$relativePath;
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

    protected function getMethodName(Endpoint $endpoint, string $requestClassName): string
    {
        $methodBaseName = str_ends_with($requestClassName, 'Request')
            ? substr($requestClassName, 0, -7)
            : $requestClassName;

        return NameHelper::safeVariableName($methodBaseName);
    }

    protected function getResourceParameterName(Parameter $parameter, bool $isPathParam): string
    {
        if ($isPathParam) {
            return $parameter->name.'Id';
        }

        return $parameter->name;
    }

    protected function customizeResourceMethod(Method $method, $namespace, array &$args, Endpoint $endpoint): void
    {
        if (! ($endpoint->method->isPost() || $endpoint->method->isPatch())) {
            return;
        }

        $dataClass = Data::class;
        $namespace->addUse($dataClass);

        $dataParam = new Parameter(
            type: $dataClass.'|array|null',
            nullable: true,
            name: 'data',
            description: 'Request data',
        );

        $this->addPropertyToMethod($method, $dataParam);
        $args[] = new Literal('$data');
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
