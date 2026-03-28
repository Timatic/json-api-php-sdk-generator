<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators;

use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Generators\FactoryGenerator;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PromotedParameter;

class JsonApiFactoryGenerator extends FactoryGenerator
{
    protected function foundationClass(string $relativePath): string
    {
        return $this->config->namespace.'\\Foundation\\'.$relativePath;
    }

    public function process(Config $config, ApiSpecification $specification, GeneratedCode $generatedCode): PhpFile|array|null
    {
        $this->factoryClass = $config->namespace.'\\Foundation\\Factories\\Factory';

        return parent::process($config, $specification, $generatedCode);
    }

    protected function getPropertiesToSkip(): array
    {
        return ['id', 'type'];
    }

    protected function collectPromotedConstructorProperties(ClassType $class, array $propertiesToSkip): array
    {
        $properties = [];

        if (! $class->hasMethod('__construct')) {
            return $properties;
        }

        foreach ($class->getMethod('__construct')->getParameters() as $param) {
            if (! $param instanceof PromotedParameter) {
                continue;
            }

            $propName = $param->getName();

            if (in_array($propName, $propertiesToSkip, true)) {
                continue;
            }

            if ($this->hasAttribute($param, 'JsonApiRelationship')) {
                continue;
            }

            $typeName = is_string($param->getType()) ? $param->getType() : null;

            $properties[] = [
                'name' => $propName,
                'type' => $typeName,
                'isDateTime' => $this->isDateTimeType($typeName),
            ];
        }

        return $properties;
    }
}
