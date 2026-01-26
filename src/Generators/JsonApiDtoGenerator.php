<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators;

use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Generator;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Crescat\SaloonSdkGenerator\Helpers\Utils;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;

class JsonApiDtoGenerator extends Generator
{
    protected array $generated = [];

    protected ApiSpecification $specification;

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

        if ($specification->components) {
            foreach ($specification->components->schemas as $className => $schema) {
                // Skip schemas that aren't useful
                if (str_ends_with($className, 'Identifier') ||
                    str_ends_with($className, 'Request')) {
                    continue;
                }

                $this->generateModelClass(NameHelper::safeClassName($className), $schema);
            }
        }

        return $this->generated;
    }

    protected function generateModelClass(string $className, Schema $schema): PhpFile
    {
        $modelName = NameHelper::dtoClassName($className);

        $classType = new ClassType($modelName);
        $classFile = new PhpFile;
        $namespace = $classFile
            ->addNamespace("{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}");

        // Get Foundation classes with target namespace
        $modelClass = $this->foundationClass('Hydration\\Model');
        $propertyClass = $this->foundationClass('Hydration\\Attributes\\Property');

        // Extend Model instead of Spatie Data
        $classType->setExtends($modelClass)
            ->setComment($schema->title ?? '')
            ->addComment('')
            ->addComment(Utils::wrapLongLines($schema->description ?? ''));

        // Extract properties from JSON:API structure
        $properties = $this->extractJsonApiProperties($schema);

        // Add properties to the class
        foreach ($properties as $propertyName => $propertySpec) {
            // Skip 'id' and 'type' as they're already defined in the base Model class
            if (in_array($propertyName, ['id', 'type'])) {
                continue;
            }

            $this->addPropertyToClass($classType, $namespace, $propertyName, $propertySpec);
        }

        // Add relationship properties
        $this->addRelationshipProperties($classType, $namespace, $schema);

        // Add imports
        $namespace->addUse($modelClass);
        $namespace->addUse($propertyClass);

        $namespace->add($classType);

        $this->generated[$modelName] = $classFile;

        return $classFile;
    }

    /**
     * Extract properties from JSON:API schema structure
     *
     * @return Schema[]
     */
    protected function extractJsonApiProperties(Schema $schema): array
    {
        // Check if this is a JSON:API schema with attributes at root level
        if (isset($schema->properties['attributes'])) {
            $attributesSchema = $schema->properties['attributes'];

            if ($attributesSchema instanceof Schema && isset($attributesSchema->properties)) {
                // Return the flattened attributes properties
                return $attributesSchema->properties;
            }
        }

        // Fallback to regular properties if not JSON:API structure
        return $schema->properties ?? [];
    }

    protected function addPropertyToClass(
        ClassType $classType,
        $namespace,
        string $propertyName,
        Schema|Reference $propertySpec
    ): void {
        $type = $this->convertOpenApiTypeToPhp($propertySpec);
        $name = NameHelper::safeVariableName($propertyName);

        // Get Foundation classes with target namespace
        $propertyClass = $this->foundationClass('Hydration\\Attributes\\Property');
        $dateTimeClass = $this->foundationClass('Hydration\\Attributes\\DateTime');

        // Create public property with #[Property] attribute
        $property = $classType->addProperty($name)
            ->setPublic()
            ->setType($type)
            ->setNullable(true);

        // Add #[Property] attribute
        $property->addAttribute($propertyClass);

        // Check if this is a datetime field by format OR by naming pattern
        $isDateTime = ($propertySpec instanceof Schema && $propertySpec->format === 'date-time')
            || $this->looksLikeDateTimeField($propertyName);

        if ($isDateTime) {
            $property->addAttribute($dateTimeClass);
            $namespace->addUse($dateTimeClass);

            // Change type to Carbon if datetime
            if (! str_contains($type, 'Carbon')) {
                $property->setType('null|\\Carbon\\Carbon');
            }
        }

        // Add comment with description if available
        if ($propertySpec instanceof Schema && $propertySpec->description) {
            $property->addComment($propertySpec->description);
        }
    }

    protected function looksLikeDateTimeField(string $name): bool
    {
        $patterns = [
            '_at$',      // snake_case: created_at, updated_at, started_at, ended_at, etc.
            'At$',       // camelCase: createdAt, updatedAt, startedAt, endedAt, etc.
            '_date$',    // snake_case: birth_date, start_date, etc.
            'Date$',     // camelCase: birthDate, startDate, etc.
            '^date_',    // snake_case: date_created, date_modified, etc.
            '^date[A-Z]', // camelCase: dateCreated, dateModified, etc.
            '_time$',    // snake_case: start_time, end_time, etc.
            'Time$',     // camelCase: startTime, endTime, etc.
            '^time_',    // snake_case: time_started, time_ended, etc.
            '^time[A-Z]', // camelCase: timeStarted, timeEnded, etc.
        ];

        foreach ($patterns as $pattern) {
            if (preg_match("/{$pattern}/", $name)) {
                return true;
            }
        }

        return false;
    }

    protected function convertOpenApiTypeToPhp(Schema|Reference $schema): string
    {
        if ($schema instanceof Reference) {
            return Str::afterLast($schema->getReference(), '/');
        }

        // Handle anyOf, oneOf, allOf
        if (isset($schema->anyOf)) {
            return $this->handleCompositeType($schema->anyOf);
        }

        if (isset($schema->oneOf)) {
            return $this->handleCompositeType($schema->oneOf);
        }

        if (isset($schema->allOf)) {
            return $this->handleCompositeType($schema->allOf);
        }

        // Handle array union types (OpenAPI 3.1.0+)
        if (is_array($schema->type)) {
            /** @var array<string> $types */
            $types = $schema->type;

            return collect($types)
                ->map(fn ($type) => $this->mapType($type))
                ->implode('|');
        }

        // Handle simple types (or null)
        if ($schema->type !== null) {
            return $this->mapType($schema->type, $schema->format);
        }

        // Fallback for schemas without type information
        return 'mixed';
    }

    protected function mapType(?string $type, ?string $format = null): string
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
                default => 'float', // Default for number without format
            },
            'array' => 'array',
            'null' => 'null',
            default => 'mixed', // Fallback for unknown types
        };
    }

    /**
     * Handle anyOf, oneOf, allOf composite types
     * Returns a PHP union type string
     */
    protected function handleCompositeType(array $types): string
    {
        $phpTypes = [];

        foreach ($types as $typeSchema) {
            if ($typeSchema instanceof Reference) {
                $phpTypes[] = Str::afterLast($typeSchema->getReference(), '/');
            } elseif ($typeSchema instanceof Schema) {
                if ($typeSchema->type !== null) {
                    if (is_array($typeSchema->type)) {
                        // Nested union
                        foreach ($typeSchema->type as $t) {
                            $phpTypes[] = $this->mapType($t, $typeSchema->format ?? null);
                        }
                    } else {
                        $phpTypes[] = $this->mapType($typeSchema->type, $typeSchema->format ?? null);
                    }
                }
            }
        }

        // Remove duplicates and return union
        return collect($phpTypes)
            ->unique()
            ->filter()
            ->implode('|') ?: 'mixed';
    }

    /**
     * Add relationship properties to the DTO class
     */
    protected function addRelationshipProperties(ClassType $classType, $namespace, Schema $schema): void
    {
        // Check if schema has relationships
        if (! isset($schema->properties['relationships'])) {
            return;
        }

        $relationships = $schema->properties['relationships'];

        if (! isset($relationships->properties) || ! is_array($relationships->properties)) {
            return;
        }

        // Get Foundation classes with target namespace
        $relationshipClass = $this->foundationClass('Hydration\\Attributes\\Relationship');
        $relationTypeClass = $this->foundationClass('Hydration\\RelationType');

        // Import required classes
        $namespace->addUse($relationshipClass);
        $namespace->addUse($relationTypeClass);
        $namespace->addUse(Collection::class);

        foreach ($relationships->properties as $relationName => $relationSpec) {
            $relationType = $this->detectRelationType($relationName, $relationSpec);
            $relatedModel = $this->detectRelatedModel($relationName, $relationSpec);

            if (! $relatedModel) {
                // Skip if we can't determine the related model
                echo "  ⚠️  Skipping relationship '{$relationName}' - model not found\n";

                continue;
            }

            // Check if related model schema exists
            if (! isset($this->specification->components->schemas[$relatedModel])) {
                echo "  ⚠️  Skipping relationship '{$relationName}' - model '{$relatedModel}' not found in schemas\n";

                continue;
            }

            // Import related model
            $namespace->addUse("{$this->config->namespace}\\Dto\\{$relatedModel}");

            // Create property
            $property = $classType->addProperty($relationName)
                ->setPublic()
                ->setNullable(true)
                ->setValue(null); // Add default value

            // Set type based on relationship type
            // Use FQN for Collection and related model to avoid backslash prefix
            if ($relationType === 'Many') {
                $property->setType('null|\\Illuminate\\Support\\Collection');
                $property->addComment("@var Collection<int, {$relatedModel}>|null");
            } else {
                $property->setType("null|\\{$this->config->namespace}\\Dto\\{$relatedModel}");
            }

            // Add Relationship attribute (use full class name for attribute)
            $property->addAttribute($relationshipClass, [
                new Literal("{$relatedModel}::class"),
                new Literal("RelationType::{$relationType}"),
            ]);
        }
    }

    /**
     * Detect relationship type (One or Many) from relationship name
     */
    protected function detectRelationType(string $relationName, $relationSpec): string
    {
        // Plural relationship names are typically "to-many"
        if (Str::plural($relationName) === $relationName) {
            return 'Many';
        }

        // Singular names are "to-one"
        return 'One';
    }

    /**
     * Detect related model class name from relationship schema
     */
    protected function detectRelatedModel(string $relationName, Schema|Reference $relationSpec): ?string
    {
        // Look at relationship.properties.data.anyOf to find the schema reference
        // Example: for budget.currentPeriod, we find PeriodIdentifier in anyOf
        // Then we strip "Identifier" to get "Period"

        if ($relationSpec instanceof Reference) {
            $relationSpec = $relationSpec->resolve();
        }

        // Navigate to properties.data.anyOf
        if (! isset($relationSpec->properties['data'])) {
            return null;
        }

        $dataSpec = $relationSpec->properties['data'];

        if ($dataSpec instanceof Reference) {
            $dataSpec = $dataSpec->resolve();
        }

        // Check for anyOf (union types)
        if (isset($dataSpec->anyOf) && is_array($dataSpec->anyOf)) {
            foreach ($dataSpec->anyOf as $typeSchema) {
                if ($typeSchema instanceof Reference) {
                    // Extract schema name from reference
                    // e.g., #/components/schemas/PeriodIdentifier -> PeriodIdentifier
                    $schemaName = Str::afterLast($typeSchema->getReference(), '/');

                    // Skip null types
                    if ($schemaName === 'null') {
                        continue;
                    }

                    // Strip "Identifier" suffix to get the DTO name
                    if (Str::endsWith($schemaName, 'Identifier')) {
                        $modelName = Str::beforeLast($schemaName, 'Identifier');

                        return $modelName;
                    }

                    // If no "Identifier" suffix, use as-is
                    return $schemaName;
                }
            }
        }

        // Fallback: convert relationship name to model name
        $singular = Str::singular($relationName);
        $modelName = NameHelper::dtoClassName($singular);

        return $modelName;
    }
}
