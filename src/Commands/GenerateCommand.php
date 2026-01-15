<?php

declare(strict_types=1);

namespace JsonApiSdk\Commands;

use Crescat\SaloonSdkGenerator\CodeGenerator;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Parsers\OpenApiParser;
use JsonApiSdk\Generators\JsonApiConnectorGenerator;
use JsonApiSdk\Generators\JsonApiDtoGenerator;
use JsonApiSdk\Generators\JsonApiFactoryGenerator;
use JsonApiSdk\Generators\JsonApiPestTestGenerator;
use JsonApiSdk\Generators\JsonApiRequestGenerator;
use JsonApiSdk\Generators\JsonApiResourceGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'generate',
    description: 'Generate a JSON:API SDK from an OpenAPI specification',
)]
class GenerateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'spec',
                InputArgument::REQUIRED,
                'Path to OpenAPI specification file (local file or URL)'
            )
            ->addOption(
                'output',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output directory for generated SDK',
                './output'
            )
            ->addOption(
                'namespace',
                null,
                InputOption::VALUE_REQUIRED,
                'Root namespace for generated code',
                'App\\Sdk'
            )
            ->addOption(
                'connector-name',
                'c',
                InputOption::VALUE_REQUIRED,
                'Name of the Connector class',
                'ApiConnector'
            )
            ->addOption(
                'tests',
                't',
                InputOption::VALUE_NONE,
                'Generate Pest tests'
            )
            ->addOption(
                'factories',
                'f',
                InputOption::VALUE_NONE,
                'Generate Faker factories'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be generated without writing files'
            )
            ->addOption(
                'force',
                null,
                InputOption::VALUE_NONE,
                'Overwrite existing files'
            )
            ->addOption(
                'config-key',
                null,
                InputOption::VALUE_REQUIRED,
                'Laravel config key for the SDK (e.g., "myapi" results in config("myapi.base_url"))'
            )
            ->addOption(
                'base-url',
                null,
                InputOption::VALUE_REQUIRED,
                'Default base URL for the API',
                'https://api.example.com'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $specPath = $input->getArgument('spec');
        $outputDir = $input->getOption('output');
        $namespace = $input->getOption('namespace');
        $connectorName = $input->getOption('connector-name');
        $generateTests = $input->getOption('tests');
        $generateFactories = $input->getOption('factories');
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');
        $baseUrl = $input->getOption('base-url');

        // Derive config key from connector name if not provided
        $configKey = $input->getOption('config-key')
            ?? strtolower(preg_replace('/Connector$/', '', $connectorName));

        $io->title('JSON:API SDK Generator');

        // Validate spec file
        if (!$this->isUrl($specPath) && !file_exists($specPath)) {
            $io->error("Specification file not found: {$specPath}");
            return Command::FAILURE;
        }

        $io->section('Configuration');
        $io->listing([
            "Spec: {$specPath}",
            "Output: {$outputDir}",
            "Namespace: {$namespace}",
            "Connector: {$connectorName}",
            "Config key: {$configKey}",
            "Tests: " . ($generateTests ? 'Yes' : 'No'),
            "Factories: " . ($generateFactories ? 'Yes' : 'No'),
        ]);

        // Create config
        $config = new Config(
            connectorName: $connectorName,
            namespace: $namespace,
            resourceNamespaceSuffix: 'Resources',
            requestNamespaceSuffix: 'Requests',
            dtoNamespaceSuffix: 'Dto',
            baseResourcePath: 'src',
        );

        // Parse specification
        $io->section('Parsing OpenAPI Specification');

        try {
            $specContent = $this->loadSpec($specPath);
            $parser = new OpenApiParser();
            $specification = $parser->parse($specContent);
            $io->success('Specification parsed successfully');
        } catch (\Exception $e) {
            $io->error("Failed to parse specification: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Configure generators
        $generators = [
            new JsonApiDtoGenerator($config),
            new JsonApiRequestGenerator($config),
            new JsonApiConnectorGenerator($config),
            new JsonApiResourceGenerator($config),
        ];

        $postProcessors = [];

        if ($generateTests) {
            $postProcessors[] = new JsonApiPestTestGenerator($config);
        }

        // Note: Factory generation requires the DTOs to be loaded first
        // This would need to run after the initial generation

        // Generate code
        $io->section('Generating SDK');

        $codeGenerator = new CodeGenerator(
            config: $config,
            generators: $generators,
            postProcessors: $postProcessors,
        );

        try {
            $result = $codeGenerator->run($specification);
            $io->success('Code generated successfully');
        } catch (\Exception $e) {
            $io->error("Failed to generate code: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Write files
        if ($dryRun) {
            $io->section('Dry Run - Files that would be generated:');
            $this->listGeneratedFiles($io, $result, $outputDir, $configKey);
        } else {
            $io->section('Writing Files');
            $this->writeGeneratedFiles($io, $result, $outputDir, $force, $configKey, $connectorName, $baseUrl);
            $io->success("SDK generated successfully in {$outputDir}");
        }

        return Command::SUCCESS;
    }

    private function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    private function loadSpec(string $path): string
    {
        if ($this->isUrl($path)) {
            $content = file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException("Failed to fetch specification from URL: {$path}");
            }
            return $content;
        }

        return file_get_contents($path);
    }

    private function listGeneratedFiles(SymfonyStyle $io, $result, string $outputDir, string $configKey): void
    {
        $files = [];

        // Config file
        $files[] = "{$outputDir}/config/{$configKey}.php";

        // Collect all generated files
        foreach ($result->connectorClass ?? [] as $name => $file) {
            $files[] = "{$outputDir}/src/{$name}.php";
        }

        foreach ($result->dtoClasses ?? [] as $name => $file) {
            $files[] = "{$outputDir}/src/Dto/{$name}.php";
        }

        foreach ($result->requestClasses ?? [] as $name => $file) {
            $files[] = "{$outputDir}/src/Requests/{$name}.php";
        }

        foreach ($result->resourceClasses ?? [] as $name => $file) {
            $files[] = "{$outputDir}/src/Resources/{$name}.php";
        }

        $io->listing($files);
        $io->note("Total: " . count($files) . " files");
    }

    private function writeGeneratedFiles(
        SymfonyStyle $io,
        $result,
        string $outputDir,
        bool $force,
        string $configKey,
        string $connectorName,
        string $baseUrl
    ): void {
        $written = 0;
        $skipped = 0;

        // Write config file
        $configContent = $this->generateConfigFile($configKey, $connectorName, $baseUrl);
        $configPath = "{$outputDir}/config/{$configKey}.php";
        if ($this->writeFile($configPath, $configContent, $force)) {
            $written++;
        } else {
            $skipped++;
        }

        // Write connector
        if (isset($result->connectorClass)) {
            foreach ($result->connectorClass as $name => $file) {
                $path = "{$outputDir}/src/{$name}.php";
                if ($this->writeFile($path, (string) $file, $force)) {
                    $written++;
                } else {
                    $skipped++;
                }
            }
        }

        // Write DTOs
        foreach ($result->dtoClasses ?? [] as $name => $file) {
            $path = "{$outputDir}/src/Dto/{$name}.php";
            if ($this->writeFile($path, (string) $file, $force)) {
                $written++;
            } else {
                $skipped++;
            }
        }

        // Write Requests
        foreach ($result->requestClasses ?? [] as $collection => $requests) {
            if (is_array($requests)) {
                foreach ($requests as $name => $file) {
                    $path = "{$outputDir}/src/Requests/{$collection}/{$name}.php";
                    if ($this->writeFile($path, (string) $file, $force)) {
                        $written++;
                    } else {
                        $skipped++;
                    }
                }
            } else {
                $path = "{$outputDir}/src/Requests/{$collection}.php";
                if ($this->writeFile($path, (string) $requests, $force)) {
                    $written++;
                } else {
                    $skipped++;
                }
            }
        }

        // Write Resources
        foreach ($result->resourceClasses ?? [] as $name => $file) {
            $path = "{$outputDir}/src/Resources/{$name}.php";
            if ($this->writeFile($path, (string) $file, $force)) {
                $written++;
            } else {
                $skipped++;
            }
        }

        // Write Tests
        foreach ($result->testClasses ?? [] as $name => $file) {
            $path = "{$outputDir}/{$name}";
            if ($this->writeFile($path, (string) $file, $force)) {
                $written++;
            } else {
                $skipped++;
            }
        }

        $io->text("Written: {$written} files");
        if ($skipped > 0) {
            $io->text("Skipped: {$skipped} files (already exist, use --force to overwrite)");
        }
    }

    private function writeFile(string $path, string $content, bool $force): bool
    {
        if (file_exists($path) && !$force) {
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $content);
        return true;
    }

    private function generateConfigFile(string $configKey, string $connectorName, string $baseUrl): string
    {
        $stubPath = dirname(__DIR__) . '/Stubs/config.php.stub';
        $stub = file_get_contents($stubPath);

        // Generate env prefix from config key (e.g., "myapi" -> "MYAPI")
        $envPrefix = strtoupper(str_replace(['-', '.'], '_', $configKey));

        $replacements = [
            '{{ connectorName }}' => $connectorName,
            '{{ envPrefix }}' => $envPrefix,
            '{{ defaultBaseUrl }}' => $baseUrl,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stub);
    }
}
