# JSON:API PHP SDK Generator
Generate a PHP SDK for API's using JSON:API

```bash
./bin/jsonapi-sdk generate ./openapi.json --output=../my-sdk/ --force 
```

For more information on the command options run `jsonapi-sdk generate --help`.
It will list all these command options:
```
Options:
  -o, --output=OUTPUT                  Output directory for generated SDK [default: "./output"]
      --foundation                     Generate Foundation support files
      --connector-name=CONNECTOR-NAME  Name of the Connector class (e.g., "TimaticConnector"). 
                                       Config key is derived from this (e.g., "timatic"). 
                                       Required when using --foundation
      --base-url=BASE-URL              Default base URL for the API.
      --skip-tests                     Skip generating Pest tests
      --skip-factories                 Skip generating Faker factories
      --dry-run                        Show what would be generated without writing files
      --force                          Overwrite existing files and remove previously generated
                                       Requests, DTOs, Factories and tests
```

When you run the command for the first time, you should add the `--foundation` flag.
This will add all the necessary files that are 'static' and these can be changed manually afterward.

Recommended first-time setup
1. Create your project, e.g. `git init`
2. Initialize Composer `composer init`, this generator depends on the psr-4 autoload namespace and the package name.
3. Then run the generator with `--foundation`.

```bash
./bin/jsonapi-sdk generate ./openapi.json \
    --output=../my-sdk/ \
    --foundation \
    --connector-name "MyConnector" \
    --base-url="https://api.myapp.com" \
```

What `--foundation` does comes down to:
- Verifies a `composer.json` exists and checks if it contains a PSR-4 `autoload` section.
- Runs `composer require` to add runtime dependencies like `saloonphp/saloon`, `nesbot/carbon`, `saloonphp/pagination-plugin`
- Runs `composer require --dev` to add development dependencies like: `faker`,`pest`, `pint` and `larastan`
- Updates your `composer.json` with PSR-4 autoload mappings
- Adds composer scripts `test`, `coverage`, `format` and `analyse`
- Enables the Pest plugin in `config.allow-plugins`.
- Adds `phpunit.xml`, `phpstan.neon`, `tests/Pest.php` and `tests/TestCase.php`.

## Regenerating

Run the generator again without `--foundation` whenever the API changes. Add `--force` so that Requests, DTOs,
Factories and tests for endpoints that were removed from the API are deleted. Files in `src/Foundation` are never
touched, so changes you made there are kept. Add `-v` to list every written file.

```bash
./bin/jsonapi-sdk generate ./openapi.json --output=../my-sdk/ --force
```

## Using the generated SDK

The examples below use an SDK generated with `--connector-name="AcmeConnector"` and the namespace `Acme\Sdk` for an
API with an `articles` resource. Class names follow the `operationId`s in your OpenAPI specification.

### Configuration

The SDK is a Laravel package that registers itself through auto-discovery. Publish the config file and add your
credentials to `.env`:

```bash
php artisan vendor:publish --tag=acme-config
```

```env
ACME_BASE_URL=https://api.acme.test
ACME_API_TOKEN=your-api-token
```

### Sending requests

The connector is registered as a singleton, so you can inject it:

```php
use Acme\Sdk\AcmeConnector;
use Acme\Sdk\DataTransferObjects\Article;
use Acme\Sdk\Requests\Articles\CreateArticleRequest;
use Acme\Sdk\Requests\Articles\GetArticleRequest;
use Acme\Sdk\Requests\Articles\ListArticlesCollectionRequest;

class ArticleController extends Controller
{
    public function __construct(
        protected AcmeConnector $acme,
    ) {}

    public function index()
    {
        // The first page, limited by the API's default page size
        $articles = $this->acme->send(new ListArticlesCollectionRequest)->dto();

        return view('articles.index', compact('articles'));
    }

    public function show(string $id)
    {
        $article = $this->acme->send(new GetArticleRequest($id))->dtoOrFail();

        return view('articles.show', compact('article'));
    }

    public function store(Request $request)
    {
        $article = Article::from(['title' => $request->input('title')]);

        $created = $this->acme->send(new CreateArticleRequest($article))->dtoOrFail();

        return redirect()->route('articles.show', $created->id);
    }
}
```

DTOs are [Spatie Data](https://spatie.be/docs/laravel-data) objects. JSON:API attributes and relationships are
flattened into typed properties, so you use `$article->title` instead of `$article->attributes->title`.
Write endpoints with their own request body schema get a separate DTO named after the action, e.g. `NewArticle` and
`UpdateArticle`.

### Pagination

Collection requests can be paginated. The paginator follows JSON:API's `page[number]` and `page[size]` and stops when
`links.next` is missing.

```php
$paginator = $acme->paginate(new ListArticlesCollectionRequest);
$paginator->setPerPageLimit(50);

// Iterate over all pages lazily
foreach ($paginator->items() as $article) {
    // ...
}

// Or fetch all pages into one Collection
$articles = $acme->paginate(new ListArticlesCollectionRequest)->dtoCollection();
```

### Includes and filters

When the specification has an `include` parameter, the request gets an `include()` method plus one method per
relationship. Filter parameters (`filter[...]`) add a `filter()` method.

```php
use Acme\Sdk\Foundation\Filtering\Operator;

$request = (new ListArticlesCollectionRequest)
    ->includeAuthor()
    ->filter('status', 'published')
    ->filter('publishedAt', '2026-01-01', Operator::GreaterThanOrEquals);

$articles = $acme->send($request)->dto();
$articles->first()->author; // Hydrated from the included resources
```

### Responses

Every response is a `JsonApiResponse`, which adds JSON:API helpers to Saloon's response:

```php
$response = $acme->send(new ListArticlesCollectionRequest);

$response->firstItem();  // Raw JSON:API data of the first item
$response->hasErrors();
$response->errors();
$response->meta();
$response->links();
$response->included();
```

### Testing

Every DTO has a factory, which you get through `testFactory()`. Combine it with `JsonApiSerializer` to fake API
responses:

```php
use Acme\Sdk\DataTransferObjects\Article;
use Acme\Sdk\Foundation\JsonApi\JsonApiSerializer;
use Acme\Sdk\Requests\Articles\ListArticlesCollectionRequest;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;

test('it lists articles', function () {
    // Given
    $article = Article::testFactory()->state(['title' => 'Hello world'])->withId()->make();

    Saloon::fake([
        ListArticlesCollectionRequest::class => MockResponse::make([
            'data' => [JsonApiSerializer::toJsonApi($article)],
        ]),
    ]);

    // When
    $response = $this->get(route('articles.index'));

    // Then
    $response->assertOk()->assertSee('Hello world');
});
```

Factories support `state([...])` to set attributes, `withId()` for a random UUID and `count(3)` to make a
Collection.

### Development

The generated SDK comes with these composer scripts:

```bash
composer test     # Pest
composer coverage
composer format   # Pint
composer analyse  # PHPStan
```
