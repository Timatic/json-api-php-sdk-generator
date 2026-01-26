<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Services;

/**
 * Copies Foundation classes to the generated SDK output directory
 * and rewrites namespaces to match the target SDK namespace.
 */
class FoundationCopier
{
    private string $sourceDir;

    public function __construct()
    {
        $this->sourceDir = dirname(__DIR__) . '/Foundation';
    }

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
        $files = $this->getFoundationFiles();

        foreach ($files as $sourceFile) {
            $relativePath = $this->getRelativePath($sourceFile);
            $targetFile = "{$outputDir}/src/Foundation/{$relativePath}";

            $content = file_get_contents($sourceFile);
            $content = $this->rewriteNamespace($content, $targetNamespace);

            $this->writeFile($targetFile, $content);
            $copied++;
        }

        return $copied;
    }

    /**
     * Get all PHP files in the Foundation directory.
     *
     * @return array<string>
     */
    private function getFoundationFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->sourceDir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Get the relative path from the Foundation source directory.
     */
    private function getRelativePath(string $filePath): string
    {
        return str_replace($this->sourceDir . '/', '', $filePath);
    }

    /**
     * Rewrite the namespace in the file content.
     */
    private function rewriteNamespace(string $content, string $targetNamespace): string
    {
        // Replace namespace declarations
        $content = str_replace(
            'namespace Timatic\JsonApiSdk\\Foundation',
            "namespace {$targetNamespace}\\Foundation",
            $content
        );

        // Replace use statements
        $content = str_replace(
            'use Timatic\JsonApiSdk\\Foundation\\',
            "use {$targetNamespace}\\Foundation\\",
            $content
        );

        // Replace fully qualified class names (with leading backslash)
        $content = str_replace(
            '\\Timatic\JsonApiSdk\\Foundation\\',
            "\\{$targetNamespace}\\Foundation\\",
            $content
        );

        return $content;
    }

    /**
     * Write content to a file, creating directories as needed.
     */
    private function writeFile(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $content);
    }
}
