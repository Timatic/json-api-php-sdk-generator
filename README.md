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
      --force                          Overwrite existing files
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
