<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Commands;

use Crescat\SaloonSdkGenerator\CodeGenerator;
use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Parsers\OpenApiParser;
use Crescat\SaloonSdkGenerator\Generators\ConfigPostProcessor;
use Crescat\SaloonSdkGenerator\Generators\ServiceProviderPostProcessor;
use Crescat\SaloonSdkGenerator\Generators\TestSetupPostProcessor;
use Crescat\SaloonSdkGenerator\Services\PintRunner;
use Timatic\JsonApiSdk\Generators\JsonApiConnectorGenerator;
use Timatic\JsonApiSdk\Generators\JsonApiDtoGenerator;
use Timatic\JsonApiSdk\Generators\JsonApiFactoryGenerator;
use Timatic\JsonApiSdk\Generators\JsonApiPestTestGenerator;
use Timatic\JsonApiSdk\Generators\JsonApiRequestGenerator;
use Timatic\JsonApiSdk\Generators\JsonApiResourceGenerator;
use Crescat\SaloonSdkGenerator\Services\ComposerSetup;
use Crescat\SaloonSdkGenerator\Services\ConfigValuesService;
use Timatic\JsonApiSdk\Services\FoundationCopier;
use Timatic\JsonApiSdk\Services\GeneratedFileWriter;
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
                'Overwrite existing files and remove previously generated Requests, DTOs, Factories and tests'
            )
            ->addOption(
                'exclude-put-requests',
                null,
                InputOption::VALUE_NONE,
                'Exclude PUT requests from generated SDK (default: PUT requests are included)'
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
        $excludePut = $input->getOption('exclude-put-requests');

        // Validate required options when --foundation is used
        if ($generateFoundation && $connectorNameOption === null) {
            $this->io->error('The --connector-name option is required when using --foundation');
            return Command::FAILURE;
        }

        if ($generateFoundation) {
            $connectorName = $connectorNameOption;
            $baseUrl = $baseUrlOption;
        } else {
            $configValues = (new ConfigValuesService)->resolveFromExisting(
                $outputDir,
                connectorName: $connectorNameOption,
                baseUrl: $baseUrlOption,
            );

            if ($configValues === null) {
                $this->io->error('No existing SDK found in output directory. Use --foundation to generate a new SDK.');
                return Command::FAILURE;
            }

            $connectorName = $configValues['connectorName'];
            $baseUrl = $configValues['baseUrl'];
        }

        $configKey = Config::deriveConfigKey($connectorName);

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
            "Exclude PUT: " . ($excludePut ? 'Yes' : 'No'),
        ]);

        $config = new Config(
            connectorName: $connectorName,
            namespace: $namespace,
            resourceNamespaceSuffix: 'Resources',
            requestNamespaceSuffix: 'Requests',
            dtoNamespaceSuffix: 'DataTransferObjects',
            extra: [
                'excludePut' => $excludePut,
            ],
            configKey: $configKey,
            baseUrl: $baseUrl,
        );

        // Parse specification
        $this->io->section('Parsing OpenAPI Specification');

        if ($this->isUrl($specPath)) {
            $specPath = $this->downloadSpec($specPath);
        }

        try {
            $parser = OpenApiParser::build($specPath);
            $specification = $parser->parse();
            $this->io->success('Specification parsed successfully');
        } catch (\Exception $e) {
            $this->io->error("Failed to parse specification: {$e->getMessage()}");
            return Command::FAILURE;
        }

        $postProcessors = [];

        if ($generateTests) {
            $postProcessors[] = new JsonApiPestTestGenerator();
            $postProcessors[] = new JsonApiFactoryGenerator();
        }

        if ($generateFoundation) {
            $postProcessors[] = new ConfigPostProcessor();
            $postProcessors[] = new ServiceProviderPostProcessor();
            $postProcessors[] = new TestSetupPostProcessor();
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

        $writer = new GeneratedFileWriter($config, $outputDir);
        $files = $writer->files($result, includeConnector: $generateFoundation);

        if ($dryRun) {
            $this->io->section('Dry Run - Files that would be generated:');
            $this->io->listing(array_keys($files));
            $this->io->note('Total: '.count($files).' files');

            return Command::SUCCESS;
        }

        if ($generateFoundation) {
            $this->io->section('Copying Foundation');
            $this->io->text('Copied: '.(new FoundationCopier)->copy($outputDir, $namespace).' files');

            $this->io->section('Composer setup');
            $composerSetup->setup($namespace, $this->io, false, $connectorName);
        }

        $this->io->section('Writing Files');

        if ($force) {
            $writer->removeGeneratedDirectories();
        }

        $written = $writer->write($files, $force);

        if ($output->isVerbose()) {
            $this->io->listing($written);
        }

        $this->io->text('Written: '.count($written).' files');
        $skipped = count($files) - count($written);
        if ($skipped > 0) {
            $this->io->text("Skipped: {$skipped} files (already exist, use --force to overwrite)");
        }

        (new PintRunner())->run($outputDir, $output);

        $this->io->success("SDK generated successfully in {$outputDir}");

        return Command::SUCCESS;
    }

    private function isUrl(string $path): bool
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
    }

    private function downloadSpec(string $url): string
    {
        $this->io->text("Downloading spec from {$url}...");

        $content = @file_get_contents($url, false, stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ]));

        if ($content === false) {
            throw new \RuntimeException("Failed to download specification from {$url}");
        }

        $extension = str_ends_with($url, '.json') ? '.json' : '.yaml';
        $tempFile = tempnam(sys_get_temp_dir(), 'openapi') . $extension;
        file_put_contents($tempFile, $content);

        return $tempFile;
    }
}
