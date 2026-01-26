<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Services;

class ConfigValuesService
{
    /**
     * Resolve config key, base URL, and connector name.
     *
     * When $generateFoundation is true, uses the provided options.
     * Otherwise, reads from existing config file, with options as override.
     *
     * @return array{configKey: string, baseUrl: ?string, connectorName: string}|null Returns null if no existing config found (without foundation)
     */
    public function resolve(
        string $outputDir,
        ?string $connectorName,
        ?string $baseUrl,
        bool $generateFoundation
    ): ?array {
        if ($generateFoundation) {
            return $this->buildFromOptions($connectorName, $baseUrl);
        }

        // Read existing config, use options as override if provided
        $existing = $this->readExisting($outputDir);

        if ($existing === null) {
            return null;
        }

        // Override with options if provided
        if ($connectorName !== null) {
            $existing['connectorName'] = $connectorName;
            $existing['configKey'] = $this->deriveConfigKey($connectorName);
        }
        if ($baseUrl !== null) {
            $existing['baseUrl'] = $baseUrl;
        }

        return $existing;
    }

    /**
     * Build config values from provided options.
     * Config key is derived from connector name.
     *
     * @return array{configKey: string, baseUrl: ?string, connectorName: string}
     */
    private function buildFromOptions(string $connectorName, ?string $baseUrl): array
    {
        return [
            'configKey' => $this->deriveConfigKey($connectorName),
            'baseUrl' => $baseUrl,
            'connectorName' => $connectorName,
        ];
    }

    /**
     * Derive config key from connector name.
     * E.g., "TimaticConnector" → "timatic", "MyApiConnector" → "myapi"
     */
    private function deriveConfigKey(string $connectorName): string
    {
        // Remove "Connector" suffix if present
        $name = preg_replace('/Connector$/i', '', $connectorName);

        // Convert to lowercase
        return strtolower($name);
    }

    /**
     * Read existing config from the output directory.
     *
     * @return array{configKey: string, baseUrl: ?string, connectorName: string}|null
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
        // Try to match env() with default value, otherwise baseUrl is null
        preg_match("/env\('[A-Z_]+_BASE_URL',\s*'([^']+)'\)/", $content, $matches);
        $baseUrl = $matches[1] ?? null;

        $connectorName = ucfirst($configKey) . 'Connector';

        return [
            'configKey' => $configKey,
            'baseUrl' => $baseUrl,
            'connectorName' => $connectorName,
        ];
    }
}
