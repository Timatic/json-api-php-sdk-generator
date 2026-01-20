<?php

declare(strict_types=1);

namespace JsonApiSdk\Services;

use Symfony\Component\Console\Style\SymfonyStyle;

class ConfigValuesService
{
    /**
     * Resolve config key, base URL, and connector name.
     *
     * When $generateFoundation is true, prompts the user interactively.
     * Otherwise, reads from existing config file.
     *
     * @return array{configKey: string, baseUrl: string, connectorName: string}|null Returns null if no existing config found (without foundation)
     */
    public function resolve(
        string $outputDir,
        string $connectorName,
        bool $generateFoundation,
        SymfonyStyle $io
    ): ?array {
        if ($generateFoundation) {
            return $this->promptForConfig($io);
        }

        return $this->readExisting($outputDir);
    }

    /**
     * Prompt user interactively for config values.
     * Connector name is derived from config key.
     *
     * @return array{configKey: string, baseUrl: string, connectorName: string}
     */
    private function promptForConfig(SymfonyStyle $io): array
    {
        $configKey = $io->ask(
            'Laravel config key for the SDK (e.g., "myapi" results in config("myapi.base_url"))',
            'myapi'
        );

        $baseUrl = $io->ask(
            'Default base URL for the API',
            'https://api.example.com'
        );

        $connectorName = ucfirst($configKey) . 'Connector';

        return [
            'configKey' => $configKey,
            'baseUrl' => $baseUrl,
            'connectorName' => $connectorName,
        ];
    }

    /**
     * Read existing config from the output directory.
     *
     * @return array{configKey: string, baseUrl: string, connectorName: string}|null
     */
    public function readExisting(string $outputDir): ?array
    {
        $configDir = $outputDir . '/config';
        if (!is_dir($configDir)) {
            return null;
        }

        $files = glob($configDir . '/*.php');
        if (empty($files)) {
            return null;
        }

        $configFile = $files[0];
        $configKey = basename($configFile, '.php');

        $content = file_get_contents($configFile);
        preg_match("/env\('[A-Z_]+_BASE_URL',\s*'([^']+)'\)/", $content, $matches);
        $baseUrl = $matches[1] ?? 'https://api.example.com';

        $connectorName = ucfirst($configKey) . 'Connector';

        return [
            'configKey' => $configKey,
            'baseUrl' => $baseUrl,
            'connectorName' => $connectorName,
        ];
    }
}
