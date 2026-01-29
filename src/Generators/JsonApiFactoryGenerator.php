<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Generators;

use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Schema;
use Crescat\SaloonSdkGenerator\Contracts\PostProcessor;
use Crescat\SaloonSdkGenerator\Data\Generator\ApiSpecification;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Data\TaggedOutputFile;
use Crescat\SaloonSdkGenerator\Generator;
use Crescat\SaloonSdkGenerator\Helpers\NameHelper;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\PhpFile;

class JsonApiFactoryGenerator implements PostProcessor
{
    protected array $generated = [];

    protected Config $config;

    protected ApiSpecification $specification;
    protected ?Schema $dtoSchema;

    /**
     * Get Foundation class with target namespace
     */
    protected function foundationClass(string $relativePath): string
    {
        return $this->config->namespace . '\\Foundation\\' . $relativePath;
    }

    public function process(Config $config, ApiSpecification $specification, GeneratedCode $generatedCode,): PhpFile|array|null
    {
        $this->config = $config;
        $this->specification = $specification;

        foreach($generatedCode->dtoClasses as $dtoClassName => $dtoClass) {
            $this->generateFactoryClass($dtoClassName, $dtoClass);
        }

        return $this->generated;
    }


    protected function generateFactoryClass(string $dtoClassName, PhpFile $dtoClass): PhpFile
    {
        $factoryName = $dtoClassName.'Factory';

        $this->dtoSchema = $this->getSchemaForDto($dtoClassName);

        $classType = new ClassType($factoryName);
        $classFile = new PhpFile;
        $namespace = $classFile->addNamespace("{$this->config->namespace}\\Factories");

        // Get Foundation classes with target namespace
        $factoryClass = $this->foundationClass('Factories\\Factory');

        // Extend base Factory
        $classType->setExtends($factoryClass);

        // Add imports
        $namespace->addUse($factoryClass);
        $dtoFullClass = "{$this->config->namespace}\\{$this->config->dtoNamespaceSuffix}\\{$dtoClassName}";
        $namespace->addUse($dtoFullClass);

        // Get DTO properties from the generated PhpFile (no reflection)
        $properties = $this->getDtoPropertiesFromPhpFile($dtoClass);

        // Add definition() method
        $definitionMethod = $classType->addMethod('definition')
            ->setReturnType('array')
            ->setProtected();

        $definitionBody = $this->generateDefinitionBody($dtoClassName, $properties, $namespace);
        $definitionMethod->setBody($definitionBody);

        // Add modelClass() method
        $modelClassMethod = $classType->addMethod('modelClass')
            ->setReturnType('string')
            ->setProtected();

        $modelClassMethod->setBody("return {$dtoClassName}::class;");

        $namespace->add($classType);

        // Instead of returning PhpFile directly, store as a tagged output file so we can control the target path
        $this->generated[$factoryName] = new TaggedOutputFile(
            tag: 'factories',
            file: (string) $classFile,
            path: "factories/{$factoryName}.php",
        );

        return $classFile;
    }

    /**
     * Get properties to skip when generating factory definitions.
     * Override this method in subclasses to customize which properties are skipped.
     *
     * @return array<int, string>
     */
    protected function getPropertiesToSkip(): array
    {
        return ['id', 'type'];
    }

    /**
     * Get the Schema object for a given DTO class name
     */
    protected function getSchemaForDto(string $dtoClassName): ?Schema
    {
        return $this->specification->components->schemas[$dtoClassName] ?? null;
    }

    /**
     * Extract properties from JSON:API schema structure (same as DtoGenerator)
     *
     * @return array<string, Schema|Reference>
     */
    protected function extractDtoProperties(): array
    {
        if (!$this->dtoSchema) {
            return [];
        }

        $attributesSchema = $this->dtoSchema->properties['attributes'];

        if ($attributesSchema instanceof Schema && isset($attributesSchema->properties)) {
            // Return properties from the attributes object
            return $attributesSchema->properties;
        }

        return [];
    }

    /**
     * Get DTO properties using the PhpFile representation (no reflection)
     *
     * @return array<array{name: string, type: ?string, isDateTime: bool}>
     */
    protected function getDtoPropertiesFromPhpFile(PhpFile $dtoClass): array
    {
        $properties = [];
        $propertiesToSkip = $this->getPropertiesToSkip();

        foreach ($dtoClass->getNamespaces() as $ns) {
            foreach ($ns->getClasses() as $class) {
                foreach ($class->getProperties() as $property) {
                    $propName = $property->getName();

                    // Skip properties defined in getPropertiesToSkip()
                    if (in_array($propName, $propertiesToSkip)) {
                        continue;
                    }

                    // Skip static and private properties
                    if ($property->isStatic() || $property->isPrivate()) {
                        continue;
                    }

                    // Skip relationship properties (they have Relationship attribute)
                    $hasRelationshipAttribute = false;
                    foreach ($property->getAttributes() as $attribute) {
                        $attrName = $attribute->getName();
                        if ($attrName === 'Relationship' || str_ends_with($attrName, '\\Relationship')) {
                            $hasRelationshipAttribute = true;
                            break;
                        }
                    }

                    if ($hasRelationshipAttribute) {
                        continue;
                    }

                    // Check if property has DateTime attribute
                    $isDateTime = false;
                    foreach ($property->getAttributes() as $attribute) {
                        $attrName = $attribute->getName();
                        if ($attrName === 'DateTime' || str_ends_with($attrName, '\\DateTime')) {
                            $isDateTime = true;
                            break;
                        }
                    }

                    // Get property type (string like 'null|\\Carbon\\Carbon' or 'string')
                    $typeName = $property->getType();
                    $typeName = is_string($typeName) ? $typeName : null;

                    // Normalize Carbon detection
                    if ($typeName) {
                        $normalized = ltrim($typeName, '?\\');
                        if (str_contains($normalized, 'Carbon\\Carbon') || str_ends_with($normalized, 'Carbon')) {
                            $isDateTime = true;
                            // Store consistent type for downstream faker mapping
                            $typeName = 'Carbon\\Carbon';
                        }
                    }

                    $properties[] = [
                        'name' => $propName,
                        'type' => $typeName,
                        'isDateTime' => $isDateTime,
                    ];
                }

                // Only consider the first class in this file
                break 2;
            }
        }

        return $properties;
    }

    /**
     * Generate the body of the definition() method
     */
    protected function generateDefinitionBody(string $dtoClassName, array $properties, $namespace): string
    {
        $lines = ['return ['];

        foreach ($properties as $property) {
            $propertyName = $property['name'];

            // Check if this property is a Reference in the schema
            $referencedDtoClass = $this->getReferencedDtoClass($propertyName);

            $fakerCall = $this->generateFakerCall(
                $propertyName,
                $property['type'],
                $property['isDateTime'],
                $referencedDtoClass
            );

            $lines[] = "    '{$propertyName}' => {$fakerCall},";
        }

        $lines[] = '];';

        // Add Carbon import if we have datetime properties
        $hasDateTime = collect($properties)->contains('isDateTime', true);
        if ($hasDateTime) {
            $namespace->addUse('Carbon\\Carbon');
        }

        return implode("\n", $lines);
    }

    /**
     * Generate appropriate Faker call for a property
     */
    protected function generateFakerCall(string $propertyName, ?string $propertyType, bool $isDateTime, ?string $referencedDtoClass = null): string
    {
        $lowerName = strtolower($propertyName);

        // Handle DateTime properties
        if ($isDateTime || $propertyType === 'Carbon\\Carbon') {
            return 'Carbon::now()->subDays($this->faker->numberBetween(0, 365))';
        }

        // Handle nested DTO properties (from OpenAPI $ref)
        if ($referencedDtoClass !== null) {
            return "{$referencedDtoClass}Factory::new()->make()";
        }

        // Handle by property type FIRST (type takes precedence over naming)
        if ($propertyType) {
            $baseType = ltrim($propertyType, '?\\');

            if ($baseType === 'bool' || $baseType === 'boolean') {
                return '$this->faker->boolean()';
            }

            if ($baseType === 'int' || $baseType === 'integer') {
                // For integer IDs, generate a number not a UUID
                if (str_ends_with($propertyName, 'Id') || str_ends_with($lowerName, '_id')) {
                    return '$this->faker->numberBetween(1, 1000)';
                }
                // Special cases for specific property names
                if (str_contains($lowerName, 'minute')) {
                    return '$this->faker->numberBetween(15, 480)';
                }

                return '$this->faker->numberBetween(1, 100)';
            }

            if ($baseType === 'float' || $baseType === 'double') {
                return '$this->faker->randomFloat(2, 0, 1000)';
            }

            if ($baseType === 'object') {
                return '(object) []';
            }

            if ($baseType === 'array') {
                return '[]';
            }
        }

        // Handle specific property names (case-insensitive) - only for string types
        if (str_contains($lowerName, 'email')) {
            return '$this->faker->safeEmail()';
        }

        // ID fields - only generate UUID for string types
        if ((str_ends_with($propertyName, 'Id') || str_ends_with($lowerName, '_id')) && (! $propertyType || str_contains($propertyType, 'string'))) {
            return '$this->faker->uuid()';
        }

        // Rate fields - only for string types (object types handled above)
        if (($lowerName === 'hourlyrate' || $lowerName === 'hourly_rate' || str_contains($lowerName, 'rate')) && (! $propertyType || str_contains($propertyType, 'string'))) {
            return "number_format(\$this->faker->randomFloat(2, 50, 150), 2, '.', '')";
        }

        if (str_contains($lowerName, 'description')) {
            return '$this->faker->sentence()';
        }

        if (str_contains($lowerName, 'title')) {
            return '$this->faker->sentence()';
        }

        // Handle by property name patterns
        if (str_ends_with($propertyName, 'Name') && ! str_starts_with($lowerName, 'user')) {
            return '$this->faker->company()';
        }

        if (str_contains($lowerName, 'name')) {
            return '$this->faker->name()';
        }

        if (str_contains($lowerName, 'number')) {
            return '$this->faker->word()';
        }

        // Default to word for strings
        return '$this->faker->word()';
    }

    /**
     * @param Schema|Reference|null $schemaProperty
     * @return string|null
     */
    public function getReferencedDtoClass(string $propertyName): ?string
    {
        // Get the schema for this DTO to check for References
        $schemaProperties = $this->extractDtoProperties();

        $schemaProperty = $schemaProperties[$propertyName] ?? null;
        $referencedDtoClass = null;

        if ($schemaProperty instanceof Reference) {
            // Extract the DTO class name from the reference
            $referencedDtoClass = \Illuminate\Support\Str::afterLast($schemaProperty->getReference(), '/');
            $referencedDtoClass = ucfirst($referencedDtoClass);
        }

        return $referencedDtoClass;
    }
}
