<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators;

use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Generators\DtoGenerator;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
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

    public function generate(ApiSpecification $specification): PhpFile|array
    {
        $this->factoryTraitClass = $this->foundationClass('JsonApi\\HasTestFactory');

        return parent::generate($specification);
    }

    protected function shouldIncludeSchema(string $className, Schema $schema): bool
    {
        return ! str_ends_with($className, 'Identifier')
            && ! str_ends_with($className, 'Request');
    }

    protected function extractProperties(Schema $schema): array
    {
        $properties = [];

        if (isset($schema->properties['id'])) {
            $properties['id'] = $schema->properties['id'];
        }

        if (isset($schema->properties['attributes'])) {
            $attributesSchema = $schema->properties['attributes'];

            if ($attributesSchema instanceof Schema && isset($attributesSchema->properties)) {
                foreach ($attributesSchema->properties as $name => $attrSchema) {
                    $properties[$name] = $attrSchema;
                }
            }
        }

        return $properties;
    }

    protected function afterDtoClassGenerated(ClassType $classType, PhpNamespace $namespace, Schema $schema): void
    {
        parent::afterDtoClassGenerated($classType, $namespace, $schema);

        $this->addRelationshipProperties($classType, $namespace, $schema);
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

        $relationshipAttributeClass = $this->foundationClass('JsonApi\\Attributes\\JsonApiRelationship');
        $relationTypeClass = $this->foundationClass('JsonApi\\RelationType');

        $namespace->addUse($relationshipAttributeClass);
        $namespace->addUse($relationTypeClass);
        $namespace->addUse(Collection::class);

        $constructor = $classType->getMethod('__construct');

        foreach ($relationships->properties as $relationName => $relationSpec) {
            $relationType = $this->detectRelationType($relationName);
            $relatedModel = $this->detectRelatedModel($relationName, $relationSpec);

            if (! $relatedModel) {
                echo "  ⚠️  Skipping relationship '{$relationName}' - model not found\n";

                continue;
            }

            if (! isset($this->specification->components->schemas[$relatedModel])) {
                echo "  ⚠️  Skipping relationship '{$relationName}' - model '{$relatedModel}' not found in schemas\n";

                continue;
            }

            $relatedDtoFqn = "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$relatedModel}";
            $namespace->addUse($relatedDtoFqn);

            $param = $constructor->addPromotedParameter($relationName)
                ->setPublic()
                ->setDefaultValue(null);

            if ($relationType === 'Many') {
                $param->setType('?\\'.Collection::class);
                $param->addComment("@var Collection<int, {$relatedModel}>|null");
            } else {
                $param->setType('?\\'.$relatedDtoFqn);
            }

            $param->addAttribute($relationshipAttributeClass, [
                new Literal("{$relatedModel}::class"),
                new Literal("RelationType::{$relationType}"),
            ]);
        }
    }

    protected function detectRelationType(string $relationName): string
    {
        return Str::plural($relationName) === $relationName ? 'Many' : 'One';
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

        return NameHelper::dtoClassName(Str::singular($relationName));
    }
}
