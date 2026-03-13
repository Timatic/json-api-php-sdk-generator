<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators;

use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use Crescat\SaloonSdkGenerator\Generators\DtoGenerator;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;

class JsonApiDtoGenerator extends DtoGenerator
{
    protected function foundationClass(string $relativePath): string
    {
        return $this->config->namespace.'\\Foundation\\'.$relativePath;
    }

    protected function shouldIncludeSchema(string $className, Schema $schema): bool
    {
        return ! str_ends_with($className, 'Identifier')
            && ! str_ends_with($className, 'Request');
    }

    protected function extractProperties(Schema $schema): array
    {
        if (isset($schema->properties['attributes'])) {
            $attributesSchema = $schema->properties['attributes'];

            if ($attributesSchema instanceof Schema && isset($attributesSchema->properties)) {
                return $attributesSchema->properties;
            }
        }

        return $schema->properties ?? [];
    }

    protected function getPropertiesToSkip(): array
    {
        return ['id', 'type'];
    }

    protected function convertOpenApiTypeToPhp(Schema|Reference $schema): string
    {
        if ($schema instanceof Reference) {
            $className = Str::afterLast($schema->getReference(), '/');

            return '\\'.$this->buildDtoFqn($className);
        }

        return parent::convertOpenApiTypeToPhp($schema);
    }

    protected function mapType($type, $format = null): string
    {
        if ($type === null) {
            return 'mixed';
        }

        return match ($type) {
            'integer' => 'int',
            'string' => 'string',
            'boolean' => 'bool',
            'object' => 'object',
            'number' => match ($format) {
                'float' => 'float',
                'int32', 'int64' => 'int',
                default => 'float',
            },
            'array' => 'array',
            'null' => 'null',
            default => 'mixed',
        };
    }

    protected function generateDtoClass(string $className, Schema $schema): PhpFile
    {
        $modelName = NameHelper::dtoClassName($className);

        $classType = new ClassType($modelName);
        $classFile = new PhpFile;
        $namespace = $classFile
            ->addNamespace("{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}");

        $modelClass = $this->foundationClass('Hydration\\Model');
        $propertyClass = $this->foundationClass('Hydration\\Attributes\\Property');

        $classType->setExtends($modelClass)
            ->setComment($schema->title ?? '')
            ->addComment('')
            ->addComment(Utils::wrapLongLines($schema->description ?? ''));

        $factoryClass = $this->config->namespace.'\\Factories\\'.$modelName.'Factory';

        $classType->addComment("@extends \\{$modelClass}<\\{$factoryClass}>");
        $classType->addComment("@method static \\{$factoryClass} factory()");

        $properties = $this->extractProperties($schema);

        foreach ($properties as $propertyName => $propertySpec) {
            if (in_array($propertyName, $this->getPropertiesToSkip())) {
                continue;
            }

            $this->addPropertyToClass($classType, $namespace, $propertyName, $propertySpec);
        }

        $this->addRelationshipProperties($classType, $namespace, $schema);

        $namespace->addUse($modelClass);
        $namespace->addUse($propertyClass);

        $namespace->add($classType);

        $this->generated[$modelName] = $classFile;

        return $classFile;
    }

    protected function addPropertyToClass(
        ClassType $classType,
        PhpNamespace $namespace,
        string $propertyName,
        Schema|Reference $propertySpec,
        ?string $type = null,
        $classConstructor = null,
    ): bool {
        $resolvedType = $type ?? $this->convertOpenApiTypeToPhp($propertySpec);
        $name = NameHelper::safeVariableName($propertyName);

        $propertyClass = $this->foundationClass('Hydration\\Attributes\\Property');
        $dateTimeClass = $this->foundationClass('Hydration\\Attributes\\DateTime');

        $property = $classType->addProperty($name)
            ->setPublic()
            ->setType($resolvedType)
            ->setNullable(true);

        $property->addAttribute($propertyClass);

        $isDateTime = ($propertySpec instanceof Schema && $propertySpec->format === 'date-time')
            || $this->looksLikeDateTimeField($propertyName);

        if ($isDateTime) {
            $property->addAttribute($dateTimeClass);
            $namespace->addUse($dateTimeClass);

            if (! str_contains($resolvedType, 'Carbon')) {
                $property->setType('null|\\Carbon\\Carbon');
            }
        }

        if ($propertySpec instanceof Schema && $propertySpec->description) {
            $property->addComment($propertySpec->description);
        }

        return false;
    }

    protected function looksLikeDateTimeField(string $name): bool
    {
        $patterns = [
            '_at$', 'At$',
            '_date$', 'Date$', '^date_', '^date[A-Z]',
            '_time$', 'Time$', '^time_', '^time[A-Z]',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match("/{$pattern}/", $name)) {
                return true;
            }
        }

        return false;
    }

    protected function addRelationshipProperties(ClassType $classType, PhpNamespace $namespace, Schema $schema): void
    {
        if (! isset($schema->properties['relationships'])) {
            return;
        }

        $relationships = $schema->properties['relationships'];

        if (! isset($relationships->properties) || ! is_array($relationships->properties)) {
            return;
        }

        $relationshipClass = $this->foundationClass('Hydration\\Attributes\\Relationship');
        $relationTypeClass = $this->foundationClass('Hydration\\RelationType');

        $namespace->addUse($relationshipClass);
        $namespace->addUse($relationTypeClass);
        $namespace->addUse(Collection::class);

        foreach ($relationships->properties as $relationName => $relationSpec) {
            $relationType = $this->detectRelationType($relationName, $relationSpec);
            $relatedModel = $this->detectRelatedModel($relationName, $relationSpec);

            if (! $relatedModel) {
                echo "  ⚠️  Skipping relationship '{$relationName}' - model not found\n";

                continue;
            }

            if (! isset($this->specification->components->schemas[$relatedModel])) {
                echo "  ⚠️  Skipping relationship '{$relationName}' - model '{$relatedModel}' not found in schemas\n";

                continue;
            }

            $namespace->addUse("{$this->config->namespace}\\Dto\\{$relatedModel}");

            $property = $classType->addProperty($relationName)
                ->setPublic()
                ->setNullable(true)
                ->setValue(null);

            if ($relationType === 'Many') {
                $property->setType('null|\\Illuminate\\Support\\Collection');
                $property->addComment("@var Collection<int, {$relatedModel}>|null");
            } else {
                $property->setType("null|\\{$this->config->namespace}\\Dto\\{$relatedModel}");
            }

            $property->addAttribute($relationshipClass, [
                new Literal("{$relatedModel}::class"),
                new Literal("RelationType::{$relationType}"),
            ]);
        }
    }

    protected function detectRelationType(string $relationName, $relationSpec): string
    {
        if (Str::plural($relationName) === $relationName) {
            return 'Many';
        }

        return 'One';
    }

    protected function detectRelatedModel(string $relationName, Schema|Reference $relationSpec): ?string
    {
        if ($relationSpec instanceof Reference) {
            $relationSpec = $relationSpec->resolve();
        }

        if (! isset($relationSpec->properties['data'])) {
            return null;
        }

        $dataSpec = $relationSpec->properties['data'];

        if ($dataSpec instanceof Reference) {
            $dataSpec = $dataSpec->resolve();
        }

        if (isset($dataSpec->anyOf) && is_array($dataSpec->anyOf)) {
            foreach ($dataSpec->anyOf as $typeSchema) {
                if ($typeSchema instanceof Reference) {
                    $schemaName = Str::afterLast($typeSchema->getReference(), '/');

                    if ($schemaName === 'null') {
                        continue;
                    }

                    if (Str::endsWith($schemaName, 'Identifier')) {
                        return Str::beforeLast($schemaName, 'Identifier');
                    }

                    return $schemaName;
                }
            }
        }

        $singular = Str::singular($relationName);

        return NameHelper::dtoClassName($singular);
    }
}
