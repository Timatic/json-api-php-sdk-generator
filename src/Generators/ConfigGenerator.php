<?php

namespace JsonApiSdk\Generators;

class ConfigGenerator
{
    /**
     * Generate config file content from stub.
     */
    public function generate(string $configKey, string $baseUrl): string
    {
        $stubPath = dirname(__DIR__) . '/Stubs/config.php.stub';
        $stub = file_get_contents($stubPath);

        $envPrefix = strtoupper(str_replace(['-', '.'], '_', $configKey));

        $replacements = [
            '{{ connectorName }}' => ucfirst($configKey),
            '{{ envPrefix }}' => $envPrefix,
            '{{ defaultBaseUrl }}' => $baseUrl,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $stub);
    }

    /**
     * Write config file to output directory.
     *
     * @return bool True if file was written, false if skipped (already exists without force)
     */
    public function write(string $outputDir, string $configKey, string $baseUrl, bool $force): bool
    {
        $content = $this->generate($configKey, $baseUrl);
        $path = "{$outputDir}/config/{$configKey}.php";

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
