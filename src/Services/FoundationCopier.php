<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Services;

/**
 * Copies the Foundation classes and their tests to the generated SDK output directory
 * and rewrites namespaces to match the target SDK namespace.
 */
class FoundationCopier
{
    private const SOURCE_NAMESPACE = 'Timatic\\JsonApiSdk\\';

    /**
     * Directories of this package that are copied to the same path in the SDK.
     */
    private const DIRECTORIES = ['src/Foundation', 'tests/Foundation'];

    /**
     * Copy all Foundation files to the output directory with rewritten namespaces.
     *
     * @param string $outputDir The output directory for the SDK
     * @param string $targetNamespace The target namespace (e.g., "Sample\Sdk")
     * @return int Number of files copied
     */
    public function copy(string $outputDir, string $targetNamespace): int
    {
        $copied = 0;

        foreach (self::DIRECTORIES as $directory) {
            $sourceDir = dirname(__DIR__, 2).'/'.$directory;

            foreach ($this->getPhpFiles($sourceDir) as $sourceFile) {
                $targetFile = "{$outputDir}/{$directory}/".str_replace($sourceDir.'/', '', $sourceFile);
                $content = str_replace(self::SOURCE_NAMESPACE, rtrim($targetNamespace, '\\').'\\', file_get_contents($sourceFile));

                $this->writeFile($targetFile, $content);
                $copied++;
            }
        }

        return $copied;
    }

    /**
     * @return array<string>
     */
    private function getPhpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        return $files;
    }

    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $content);
    }
}
