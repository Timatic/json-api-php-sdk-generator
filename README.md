# JSON:API PHP SDK Generator
Generate a PHP SDK for API's using JSON:API

```bash
./bin/jsonapi-sdk generate \
    --output=../my-sdk/ \
    --namespace="MySdk" \
    --connector-name "MyConnector" \
    --tests \
    --factories \
    ./openapi.json
```

For more information on the command options run `jsonapi-sdk generate --help`.
It will list all these command options:
```
Options:
  -o, --output=OUTPUT                  Output directory for generated SDK [default: "./output"]
      --namespace=NAMESPACE            Root namespace for generated code [default: "App\Sdk"]
  -c, --connector-name=CONNECTOR-NAME  Name of the Connector class [default: "ApiConnector"]
  -t, --tests                          Generate Pest tests
  -f, --factories                      Generate Faker factories  
      --foundation                     Generate Foundation support files
      --dry-run                        Show what would be generated without writing files
      --force                          Overwrite existing files
      --config-key=CONFIG-KEY          Laravel config key for the SDK (e.g., "myapi" results in config("myapi.base_url"))
      --base-url=BASE-URL              Default base URL for the API [default: "https://api.example.com"]
```

When you run the command for the first time, you should add the `--foundation` flag.
This will add all the necessary files that are 'static' and these can be changed manually afterward.
