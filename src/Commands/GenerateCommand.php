<?php

declare(strict_types=1);

namespace JsonApiSdk\Commands;

use Crescat\SaloonSdkGenerator\CodeGenerator;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\TaggedOutputFile;
use Crescat\SaloonSdkGenerator\Parsers\OpenApiParser;
use JsonApiSdk\Generators\JsonApiConnectorGenerator;
use JsonApiSdk\Generators\JsonApiDtoGenerator;
use JsonApiSdk\Generators\JsonApiFactoryGenerator;
use JsonApiSdk\Generators\JsonApiPestTestGenerator;
use JsonApiSdk\Generators\JsonApiRequestGenerator;
use JsonApiSdk\Generators\JsonApiResourceGenerator;
use JsonApiSdk\Generators\ServiceProviderGenerator;
use JsonApiSdk\Generators\TestSetupGenerator;
use JsonApiSdk\Generators\FoundationTestGenerator;
use JsonApiSdk\Services\ConfigValuesService;
use JsonApiSdk\Services\FoundationCopier;
use JsonApiSdk\Services\ComposerSetup;
use JsonApiSdk\Services\PintRunner;
use JsonApiSdk\Generators\ConfigGenerator;
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
    private SymfonyStyle $io;

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
                'connector-name',
                null,
                InputOption::VALUE_REQUIRED,
                'Name of the Connector class (e.g., "TimaticConnector"). Config key is derived from this (e.g., "timatic"). Required when using --foundation'
            )
            ->addOption(
                'skip-tests',
                null,
                InputOption::VALUE_NONE,
                'Skip generating Pest tests'
            )
            ->addOption(
                'skip-factories',
                null,
                InputOption::VALUE_NONE,
                'Skip generating Faker factories'
            )
            ->addOption(
                'foundation',
                null,
                InputOption::VALUE_NONE,
                'Generate Foundation support files'
            )
            ->addOption(
                'base-url',
                null,
                InputOption::VALUE_REQUIRED,
                'Default base URL for the API (optional)'
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
;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);

        $specPath = $input->getArgument('spec');
        $outputDir = $input->getOption('output');
        $composerPath = rtrim($outputDir, '/').'/composer.json';

        try {
            $composerSetup = new ComposerSetup($composerPath);
            $namespace = $composerSetup->getNamespace();
        } catch (\RuntimeException $e) {
            $this->io->error($e->getMessage());
            return Command::FAILURE;
        }
        $generateTests = !$input->getOption('skip-tests');
        $generateFactories = !$input->getOption('skip-factories');
        $dryRun = $input->getOption('dry-run');
        $force = $input->getOption('force');

        $generateFoundation = $input->getOption('foundation');
        $connectorNameOption = $input->getOption('connector-name');
        $baseUrlOption = $input->getOption('base-url');

        // Validate required options when --foundation is used
        if ($generateFoundation && $connectorNameOption === null) {
            $this->io->error('The --connector-name option is required when using --foundation');
            return Command::FAILURE;
        }

        // Determine config key, base URL, and connector name
        $configValuesService = new ConfigValuesService();
        $configValues = $configValuesService->resolve($outputDir, $connectorNameOption, $baseUrlOption, $generateFoundation);

        if ($configValues === null) {
            $this->io->error('No existing SDK found in output directory. Use --foundation to generate a new SDK.');
            return Command::FAILURE;
        }

        $configKey = $configValues['configKey'];
        $baseUrl = $configValues['baseUrl'];
        // Use connector name from config (derived from config key) when --foundation is used
        $connectorName = $configValues['connectorName'];

        $this->io->title('JSON:API SDK Generator');

        // Validate spec file
        if (!$this->isUrl($specPath) && !file_exists($specPath)) {
            $this->io->error("Specification file not found: {$specPath}");
            return Command::FAILURE;
        }

        $this->io->section('Configuration');
        $this->io->listing([
            "Spec: {$specPath}",
            "Output: {$outputDir}",
            "Namespace (from composer.json): {$namespace}",
            "Connector: {$connectorName}",
            "Config key: {$configKey}",
            "Tests: " . ($generateTests ? 'Yes' : 'No'),
            "Factories: " . ($generateFactories ? 'Yes' : 'No'),
            "Foundation: " . ($generateFoundation ? 'Yes' : 'No'),
        ]);

        // Create config
        $config = new Config(
            connectorName: $connectorName,
            namespace: $namespace,
            resourceNamespaceSuffix: 'Resources',
            requestNamespaceSuffix: 'Requests',
            dtoNamespaceSuffix: 'Dto',
        );

        // Parse specification
        $this->io->section('Parsing OpenAPI Specification');

        try {
            // OpenApiParser::build() takes a file path and parses it directly
            $parser = OpenApiParser::build($specPath);
            $specification = $parser->parse();
            $this->io->success('Specification parsed successfully');
        } catch (\Exception $e) {
            $this->io->error("Failed to parse specification: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Configure post-processors
        $postProcessors = [];

        if ($generateTests) {
            $postProcessors[] = new JsonApiPestTestGenerator();
            $postProcessors[] = new JsonApiFactoryGenerator();
        }

        // Generate code
        $this->io->section('Generating SDK');

        $codeGenerator = new CodeGenerator(
            config: $config,
            requestGenerator: new JsonApiRequestGenerator($config),
            resourceGenerator: new JsonApiResourceGenerator($config),
            dtoGenerator: new JsonApiDtoGenerator($config),
            connectorGenerator: new JsonApiConnectorGenerator($config),
            postProcessors: $postProcessors,
        );

        try {
            $result = $codeGenerator->run($specification);
            $this->io->success('Code generated successfully');
        } catch (\Exception $e) {
            $this->io->error("Failed to generate code: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Write files
        if ($dryRun) {
            $this->io->section('Dry Run - Files that would be generated:');
            $this->listGeneratedFiles($this->io, $result, $outputDir, $configKey);
        } else {
            $this->io->section('Writing Files');
            $this->writeGeneratedFiles($this->io, $result, $outputDir, $force, $configKey, $connectorName, $baseUrl, $namespace, $generateFoundation, $dryRun, $composerSetup);

            // Run Pint to format generated files
            (new PintRunner())->run($outputDir, $this->io);

            $this->io->success("SDK generated successfully in {$outputDir}");
        }

        return Command::SUCCESS;
    }

    private function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
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

        // Additional files (e.g., factories/tests) with explicit paths
        foreach ($result->additionalFiles ?? [] as $file) {
            if ($file instanceof TaggedOutputFile) {
                $files[] = rtrim($outputDir, '/').'/'.ltrim($file->path, '/');
            }
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
        ?string $baseUrl,
        string $namespace,
        bool $generateFoundation,
        bool $dryRun,
        ComposerSetup $composerSetup
    ): void {
        $written = 0;
        $skipped = 0;

        // Copy Foundation files only when explicitly requested
        if ($generateFoundation) {
            $foundationCopier = new FoundationCopier();
            $foundationCount = $foundationCopier->copy($outputDir, $namespace);
            $written += $foundationCount;

            // Generate ServiceProvider
            $serviceProviderGenerator = new ServiceProviderGenerator($namespace, $configKey, $connectorName);
            $serviceProviderFile = $serviceProviderGenerator->generate();
            $serviceProviderClassName = $serviceProviderGenerator->getClassName();
            $serviceProviderPath = "{$outputDir}/src/Providers/{$serviceProviderClassName}.php";
            if ($this->writeFile($serviceProviderPath, (string) $serviceProviderFile, $force)) {
                $written++;
            } else {
                $skipped++;
            }

            // Generate test setup files (Pest.php and TestCase.php)
            $testSetupGenerator = new TestSetupGenerator($namespace, $configKey, $connectorName, $baseUrl);

            // Write Pest.php
            $pestPhpPath = "{$outputDir}/tests/Pest.php";
            if ($this->writeFile($pestPhpPath, $testSetupGenerator->generatePestPhp(), $force)) {
                $written++;
            } else {
                $skipped++;
            }

            // Write TestCase.php
            $testCaseFile = $testSetupGenerator->generateTestCase();
            $testCasePath = "{$outputDir}/tests/TestCase.php";
            if ($this->writeFile($testCasePath, (string) $testCaseFile, $force)) {
                $written++;
            } else {
                $skipped++;
            }

            // After copying Foundation files, ensure composer.json exists and add required packages and settings
            $io->section('Composer setup');
            $composerSetup->setup($namespace, $io, $dryRun, $connectorName);
        }

        // Write config file
        if ((new ConfigGenerator())->write($outputDir, $configKey, $baseUrl, $force)) {
            $written++;
        } else {
            $skipped++;
        }

        // Write connector (single PhpFile, not array)
        if ($result->connectorClass !== null) {
            $className = $this->getClassNameFromPhpFile($result->connectorClass);
            $path = "{$outputDir}/src/{$className}.php";
            if ($this->writeFile($path, (string) $result->connectorClass, $force)) {
                $written++;
            } else {
                $skipped++;
            }
        }

        // Write DTOs
        foreach ($result->dtoClasses ?? [] as $file) {
            $className = $this->getClassNameFromPhpFile($file);
            $path = "{$outputDir}/src/Dto/{$className}.php";
            if ($this->writeFile($path, (string) $file, $force)) {
                $written++;
            } else {
                $skipped++;
            }
        }

        // Write Requests - extract namespace suffix for subdirectory
        foreach ($result->requestClasses ?? [] as $file) {
            $className = $this->getClassNameFromPhpFile($file);
            $subDir = $this->getRequestSubdirectory($file);
            $path = "{$outputDir}/src/Requests/{$subDir}/{$className}.php";
            if ($this->writeFile($path, (string) $file, $force)) {
                $written++;
            } else {
                $skipped++;
            }
        }

        // Write additional files (tests, factories, etc.) from post-processors
        foreach ($result->additionalFiles ?? [] as $file) {
            if ($file instanceof TaggedOutputFile) {
                // Respect provided relative path
                $path = rtrim($outputDir, '/').'/'.ltrim($file->path, '/');
                $content = is_string($file->file) ? $file->file : (string) $file->file;
                if ($this->writeFile($path, $content, $force)) {
                    $written++;
                } else {
                    $skipped++;
                }
                continue;
            }

            if ($file instanceof \Nette\PhpGenerator\PhpFile) {
                // Default PhpFile additional files go into tests directory (backwards compatible)
                $className = $this->getClassNameFromPhpFile($file);
                $path = "{$outputDir}/tests/{$className}.php";
                if ($this->writeFile($path, (string) $file, $force)) {
                    $written++;
                } else {
                    $skipped++;
                }
            }
        }

        $io->text("Written: {$written} files");
        if ($skipped > 0) {
            $io->text("Skipped: {$skipped} files (already exist, use --force to overwrite)");
        }
    }

    /**
     * Extract the class name from a PhpFile
     */
    private function getClassNameFromPhpFile(\Nette\PhpGenerator\PhpFile $file): string
    {
        foreach ($file->getNamespaces() as $namespace) {
            foreach ($namespace->getClasses() as $class) {
                return $class->getName();
            }
        }
        throw new \RuntimeException('No class found in PhpFile');
    }

    /**
     * Extract subdirectory name from request namespace (e.g., "Articles" from "Sample\Sdk\Requests\Articles")
     */
    private function getRequestSubdirectory(\Nette\PhpGenerator\PhpFile $file): string
    {
        foreach ($file->getNamespaces() as $namespace) {
            $parts = explode('\\', $namespace->getName());
            // Return the last part (e.g., "Articles" from "Sample\Sdk\Requests\Articles")
            return end($parts);
        }
        return 'Misc';
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

}
