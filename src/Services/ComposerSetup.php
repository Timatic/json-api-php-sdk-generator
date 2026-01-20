<?php

declare(strict_types=1);

namespace JsonApiSdk\Services;

use Symfony\Component\Console\Style\SymfonyStyle;

class ComposerSetup
{
    private string $composerPath;

    /** @var array<string, mixed> */
    private array $composerJson;

    /**
     * @throws \RuntimeException if composer.json is missing or has no PSR-4 autoload
     */
    public function __construct(string $composerPath)
    {
        if (! file_exists($composerPath)) {
            throw new \RuntimeException("composer.json not found at {$composerPath}. Please run 'composer init' first.");
        }

        $this->composerPath = $composerPath;
        $this->composerJson = json_decode(file_get_contents($composerPath) ?: '[]', true) ?? [];

        $psr4 = $this->composerJson['autoload']['psr-4'] ?? [];
        if (! is_array($psr4) || empty($psr4)) {
            throw new \RuntimeException('composer.json is missing an autoload.psr-4 section. Please add a PSR-4 autoload (e.g. {"App\\Sdk\\": "src/"}) and re-run.');
        }
    }

    /**
     * Extract the PSR-4 namespace from composer.json.
     *
     * Prefers the namespace that maps to src/, otherwise falls back to the first PSR-4 entry.
     */
    public function getNamespace(): string
    {
        $psr4 = $this->composerJson['autoload']['psr-4'];

        // Prefer mapping that points to src/
        foreach ($psr4 as $ns => $path) {
            if (rtrim($path, '/\\') === 'src') {
                return rtrim($ns, '\\');
            }
        }

        return rtrim(array_key_first($psr4), '\\');
    }

    /**
     * Ensure composer dependencies, autoload, and scripts are configured in the generated SDK.
     *
     * Behavior:
     * - Runs `composer require` and `composer require --dev` to add needed packages.
     * - Merges PSR-4 autoload mappings for Generator and Factories; keeps existing entries.
     * - Adds scripts for Pest, Pint, and Larastan; enables Pest plugin.
     * - Adds Laravel auto-discovery for the ServiceProvider.
     * - Runs `composer dump-autoload`.
     */
    public function setup(string $namespace, SymfonyStyle $io, bool $dryRun = false, ?string $connectorName = null): void
    {
        $outputDir = dirname($this->composerPath);

        $this->mergeDefaults($namespace, $io, $dryRun, $connectorName);

        $this->runComposerRequires($outputDir, $io, $dryRun);

        $this->runComposer($outputDir, 'composer dump-autoload', $io, $dryRun);

        $this->runComposer($outputDir, 'composer normalize', $io, $dryRun);
    }

    private function runComposerRequires(string $outputDir, SymfonyStyle $io, bool $dryRun): void
    {
        // Runtime packages (skip php platform constraint in commands)
        $require = [
            'saloonphp/saloon:^3.0',
            'saloonphp/laravel-plugin:^3.0',
            'nesbot/carbon:^2.0|^3.0',
            'saloonphp/pagination-plugin:^2.0',
        ];

        // Dev packages
        $requireDev = [
            'fakerphp/faker:^1.23',
            'laravel/boost:^1.8',
            'phpunit/phpunit:^10.0|^11.0',
            'pestphp/pest:^2.0|^3.0',
            'laravel/pint:^1.0',
            'larastan/larastan:^3.0',
            'orchestra/testbench:^8.0|^9.0|^10.0',
            'ergebnis/composer-normalize'
        ];

        // Quote each package spec to prevent shell interpretation of | as pipe
        $quotedRequire = array_map(fn ($pkg) => "'{$pkg}'", $require);
        $quotedRequireDev = array_map(fn ($pkg) => "'{$pkg}'", $requireDev);

        $this->runComposer($outputDir, 'composer require --no-interaction '.implode(' ', $quotedRequire), $io, $dryRun);
        $this->runComposer($outputDir, 'composer require --no-interaction --dev '.implode(' ', $quotedRequireDev), $io, $dryRun);
    }

    private function mergeDefaults(string $namespace, SymfonyStyle $io, bool $dryRun, ?string $connectorName = null): void
    {
        $ns = rtrim($namespace, '\\').'\\';

        $defaults = [
            'autoload' => ['psr-4' => [
                $ns => 'src/',
                $ns.'Generator\\' => 'generator/',
                $ns.'Factories\\' => 'factories/',
            ]],
            'autoload-dev' => ['psr-4' => [$ns.'Tests\\' => 'tests/']],
            'scripts' => [
                'test' => 'pest',
                'coverage' => 'pest --coverage --coverage-clover coverage.xml',
                'format' => 'pint',
                'analyse' => 'phpstan analyse --memory-limit=2G',
            ],
            'config' => ['allow-plugins' => ['pestphp/pest-plugin' => true, 'ergebnis/composer-normalize' => true]],
        ];

        // Add Laravel auto-discovery if connector name is provided
        if ($connectorName) {
            $appName = preg_replace('/Connector$/', '', $connectorName);
            $serviceProviderClass = $ns . 'Providers\\' . $appName . 'ServiceProvider';

            $defaults['extra'] = [
                'laravel' => [
                    'providers' => [$serviceProviderClass],
                ],
            ];
        }

        $this->composerJson = array_replace_recursive($defaults, $this->composerJson);

        $newContent = json_encode($this->composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";

        if ($dryRun) {
            $io->note('Would update composer.json (autoload, autoload-dev, scripts, config.allow-plugins, extra.laravel)');

            return;
        }

        file_put_contents($this->composerPath, $newContent);
        $io->writeln('- Updated: composer.json (autoload, scripts, plugins, laravel auto-discovery)');
    }

    private function runComposer(string $workingDir, string $command, SymfonyStyle $io, bool $dryRun): void
    {
        if ($dryRun) {
            $io->writeln("- Would run in {$workingDir}: {$command}");

            return;
        }

        $io->writeln("- Running: {$command}");

        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $workingDir);

        if (! \is_resource($process)) {
            $io->warning('Failed to start composer process.');

            return;
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($stdout) {
            $io->write($stdout);
        }
        if ($exitCode !== 0) {
            $io->warning("Composer command failed ({$exitCode}).\n{$stderr}");
        }
    }
}
