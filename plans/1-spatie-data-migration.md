# Plan: Replace custom Model with Spatie Data

## Context

The base `saloon-sdk-generator` DtoGenerator now produces clean Spatie Data classes (as seen in visma-sdk). The JSON:API generator currently uses a custom `Model` base class with reflection-based hydration (`Hydrator`, `#[Property]`, `#[Relationship]`, `#[DateTime]` attributes) and serialization (`toJsonApi()`).

**Goal**: Drop the custom Model/Hydrator system in favor of Spatie Data to leverage its ecosystem (validation, transformers, TypeScript generation, etc.).

**Approach chosen by user**:
- **Hydration**: Pre-processing layer flattens JSON:API structure → `Data::from()`
- **Serialization**: Separate `JsonApiSerializer` class converts Spatie Data → JSON:API format
- **Relationships**: `#[JsonApiRelationship]` attribute to distinguish relationships from attributes

## What changes

### Generated DTO output (before → after)

```php
// BEFORE: Custom Model with #[Property] attributes
class Budget extends Model
{
    #[Property]
    public ?string $budgetTypeId;
    #[Property] #[DateTime]
    public ?\Carbon\Carbon $startedAt;
    #[Relationship(Entry::class, RelationType::Many)]
    public ?Collection $entries = null;
}

// AFTER: Spatie Data with constructor promotion
class Budget extends SpatieData
{
    use HasTestFactory;

    public function __construct(
        public ?string $id = null,
        public ?string $budgetTypeId = null,
        public ?\Carbon\Carbon $startedAt = null,
        #[JsonApiRelationship(Entry::class, RelationType::Many)]
        /** @var DataCollection<int, Entry>|null */
        public ?DataCollection $entries = null,
        #[JsonApiRelationship(BudgetType::class, RelationType::One)]
        public ?BudgetType $budgetType = null,
    ) {}
}
```

### Generated Request hydration (before → after)

```php
// BEFORE:
return Hydrator::hydrate(Budget::class, $response->json('data'), $response->json('included'));

// AFTER:
return Budget::from(JsonApiTransformer::flatten($response->json('data'), $response->json('included')));
```

### Generated Request serialization (before → after)

```php
// BEFORE (mutation request):
public function __construct(protected Model|array|null $data = null) {}
protected function defaultBody(): array {
    return $this->data ? ['data' => $this->data->toJsonApi()] : [];
}

// AFTER:
public function __construct(protected Budget|array|null $data = null) {}
protected function defaultBody(): array {
    return $this->data ? ['data' => JsonApiSerializer::toJsonApi($this->data)] : [];
}
```

---

## Phase 1: New Foundation classes

Create replacements for the hydration/serialization system.

### 1a. `Foundation/JsonApi/JsonApiTransformer.php`
Flattens JSON:API response structure into a flat array that `Data::from()` can consume.

- `flatten(array $data, ?array $included): array` — takes `response->json('data')` + `response->json('included')`
- Extracts `id` from `data.id`
- Extracts attributes from `data.attributes` to top level
- Resolves relationships: looks up each relationship ref in `included`, recursively flattens, adds under relationship name
- For has-many: produces array of flattened objects
- For has-one: produces single flattened object
- `flattenCollection(array $data, ?array $included): array` — same but for arrays of data items

### 1b. `Foundation/JsonApi/JsonApiSerializer.php`
Converts Spatie Data objects back to JSON:API format.

- `toJsonApi(Data $data): array` — returns `{type, id, attributes, relationships}`
- Uses reflection to find `#[JsonApiRelationship]` properties → goes into `relationships`
- Everything else (except `id`) → goes into `attributes`
- Derives `type` from class name (plural camelCase, like current `Model::type()`)
- Handles nested serialization for relationships

### 1c. `Foundation/JsonApi/Attributes/JsonApiRelationship.php`
PHP attribute to mark relationship properties on generated DTOs.

```php
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class JsonApiRelationship {
    public function __construct(
        public string $model,      // Related Data class
        public RelationType $type, // Many or One
    ) {}
}
```

### 1d. `Foundation/JsonApi/RelationType.php`
Keep the existing enum, just move it:
```php
enum RelationType { case Many; case One; }
```

### 1e. Update `Foundation/Factories/Factory.php`
Adapt to create Spatie Data instances instead of Model instances:
- `make()` uses `$modelClass::from($attributes)` instead of `new $modelClass($attributes)`
- Or align with visma-sdk's `HasTestFactory` trait pattern from the base generator

**Files to create/modify:**
- `src/Foundation/JsonApi/JsonApiTransformer.php` (new)
- `src/Foundation/JsonApi/JsonApiSerializer.php` (new)
- `src/Foundation/JsonApi/Attributes/JsonApiRelationship.php` (new)
- `src/Foundation/JsonApi/RelationType.php` (new)
- `src/Foundation/Factories/Factory.php` (modify)

---

## Phase 2: Update JsonApiDtoGenerator

Simplify the generator to leverage the base `DtoGenerator` more.

### Key changes:
- **Drop** the full `generateDtoClass()` override — use the base (produces Spatie Data classes)
- **Drop** `addPropertyToClass()` override — base handles constructor promotion + MapName
- **Drop** `mapType()` override — base is sufficient (or keep if `number` → `float` default matters)
- **Drop** `convertOpenApiTypeToPhp()` override — base handles references
- **Drop** `looksLikeDateTimeField()` — Spatie Data handles Carbon casting via `date-time` format
- **Keep** `shouldIncludeSchema()` — still need to filter Identifier/Request schemas
- **Update** `extractProperties()` — extract from `attributes` sub-schema AND include `id` from root
- **Add/update** `afterDtoClassGenerated()` — add relationship properties with `#[JsonApiRelationship]` to constructor
- **Set** `$this->factoryTraitClass` in constructor to enable `HasTestFactory` trait on DTOs

### `extractProperties()` change:
```php
protected function extractProperties(Schema $schema): array
{
    $properties = [];

    // Include id from root level
    if (isset($schema->properties['id'])) {
        $properties['id'] = $schema->properties['id'];
    }

    // Extract from attributes sub-schema
    if (isset($schema->properties['attributes'])) {
        $attributesSchema = $schema->properties['attributes'];
        if ($attributesSchema instanceof Schema) {
            $properties = array_merge($properties, $attributesSchema->properties ?? []);
        }
    }

    return $properties;
}
```

### `afterDtoClassGenerated()` — add relationships:
- Read `schema.properties.relationships`
- For each relationship, detect type (Many/One) and related model (reuse existing detection logic)
- Add promoted constructor parameter with `#[JsonApiRelationship]` attribute
- Use `?DataCollection` for Many, `?RelatedDto` for One

**Files to modify:**
- `src/Generators/JsonApiDtoGenerator.php`

---

## Phase 3: Update JsonApiRequestGenerator

### 3a. Update `addHydrationSupport()`
Replace `Hydrator::hydrate()` / `Hydrator::hydrateCollection()` with:
```php
// Collection:
Budget::collect(JsonApiTransformer::flattenCollection($response->json('data'), $response->json('included')))
// Singular:
Budget::from(JsonApiTransformer::flatten($response->json('data'), $response->json('included')))
```

### 3b. Update `addRequestBodyParameter()`
- Replace `Model` type hint with specific DTO class (or `Data` base)
- Replace `$this->data->toJsonApi()` with `JsonApiSerializer::toJsonApi($this->data)`
- Update `getRequestBodyDtoType()` fallback from `Model` to `Data`

### 3c. Update foundation class references
- Remove references to `Hydration\Model`, `Hydration\Facades\Hydrator`
- Add references to `JsonApi\JsonApiTransformer`, `JsonApi\JsonApiSerializer`

**Files to modify:**
- `src/Generators/JsonApiRequestGenerator.php`

---

## Phase 4: Update JsonApiFactoryGenerator

- Update to work with Spatie Data factories (align with base `FactoryGenerator` pattern)
- Remove `id` skip if `id` is now a regular constructor property
- Keep `type` skip (not a property anymore)
- Ensure factory `make()` returns Spatie Data instances

**Files to modify:**
- `src/Generators/JsonApiFactoryGenerator.php`

---

## Phase 5: Update test generators

### 5a. `DtoAssertions` trait
- Remove check for `Relationship` attribute (old)
- Add check for `JsonApiRelationship` attribute to skip relationship properties in assertions

### 5b. `MutationRequestTestGenerator`
- Update factory usage if API changed (e.g., `testFactory()` vs `factory()`)
- Update `$data->toJsonApi()` references to use `JsonApiSerializer`

### 5c. `TestDataGeneratorTrait`
- Update DateTime detection (no longer uses custom `#[DateTime]` attribute, relies on Carbon type)

**Files to modify:**
- `src/Generators/TestGenerators/Traits/DtoAssertions.php`
- `src/Generators/TestGenerators/MutationRequestTestGenerator.php`
- `src/Generators/TestGenerators/Traits/TestDataGeneratorTrait.php`
- `src/Generators/TestGenerators/CollectionRequestTestGenerator.php` (minor)
- `src/Generators/TestGenerators/SingularGetRequestTestGenerator.php` (minor)

---

## Phase 6: Update resource generator and cleanup

### 6a. `JsonApiResourceGenerator`
- Update `Model` type references to `Data` or specific DTO

### 6b. Remove old Foundation files
- `src/Foundation/Hydration/Model.php`
- `src/Foundation/Hydration/Hydrator.php`
- `src/Foundation/Hydration/HasAttributes.php`
- `src/Foundation/Hydration/ModelInterface.php`
- `src/Foundation/Hydration/Facades/Hydrator.php`
- `src/Foundation/Hydration/RelationType.php`
- `src/Foundation/Hydration/Attributes/Property.php`
- `src/Foundation/Hydration/Attributes/Relationship.php`
- `src/Foundation/Hydration/Attributes/DateTime.php`

### 6c. Update `FoundationCopier` / `GenerateCommand`
- Ensure new Foundation files are copied to generated SDKs
- Remove old Hydration directory from copy list

**Files to modify:**
- `src/Generators/JsonApiResourceGenerator.php`
- `src/Services/FoundationCopier.php`
- `src/Commands/GenerateCommand.php`

**Files to delete:**
- 9 files in `src/Foundation/Hydration/`

---

## Verification

After each phase, regenerate the timatic-php-sdk and verify:

```bash
# Regenerate the SDK
cd ../timatic-php-sdk && php artisan generate:sdk

# Check generated DTOs extend Spatie Data
grep -r "extends SpatieData" src/Dto/ | head -5

# Check hydration uses JsonApiTransformer
grep -r "JsonApiTransformer" src/Requests/ | head -5

# Check serialization uses JsonApiSerializer
grep -r "JsonApiSerializer" src/Requests/ | head -5

# Run existing tests
cd ../timatic-php-sdk && ./vendor/bin/pest

# Run pint
cd ../timatic-php-sdk && ./vendor/bin/pint --test
```

After full migration, verify:
1. All DTOs extend `SpatieData` (not `Model`)
2. No references to old `Hydrator`, `Property`, `Relationship`, `DateTime` attributes
3. Relationships still work (hydration from `included` + serialization to JSON:API)
4. Factories produce valid Spatie Data instances
5. Tests pass