<?php

declare(strict_types=1);

namespace Timatic\JsonApiSdk\Services;

use Crescat\SaloonSdkGenerator\Data\Generator\Config;
use Crescat\SaloonSdkGenerator\Data\Generator\GeneratedCode;
use Crescat\SaloonSdkGenerator\Data\TaggedOutputFile;
use Nette\PhpGenerator\PhpFile;

/**
 * Maps generated code to paths in the SDK output directory and writes it there.
 */
class GeneratedFileWriter
{
    private string $outputDir;

    public function __construct(
        private readonly Config $config,
        string $outputDir,
    ) {
        $this->outputDir = rtrim($outputDir, '/');
    }

    /**
     * @return array<string, string> file contents keyed by path
     */
    public function files(GeneratedCode $generatedCode, bool $includeConnector): array
    {
        $files = [];

        if ($includeConnector && $generatedCode->connectorClass !== null) {
            $files["{$this->outputDir}/src/{$this->getClassName($generatedCode->connectorClass)}.php"] = (string) $generatedCode->connectorClass;
        }

        foreach ($generatedCode->dtoClasses as $file) {
            $files["{$this->outputDir}/src/{$this->config->dtoNamespaceSuffix}/{$this->getClassName($file)}.php"] = (string) $file;
        }

        foreach ($generatedCode->requestClasses as $file) {
            $files["{$this->outputDir}/src/{$this->config->requestNamespaceSuffix}/{$this->getRequestSubdirectory($file)}/{$this->getClassName($file)}.php"] = (string) $file;
        }

        foreach ($generatedCode->additionalFiles as $file) {
            if ($file instanceof TaggedOutputFile) {
                $files["{$this->outputDir}/".ltrim($file->path, '/')] = (string) $file->file;
            } elseif ($file instanceof PhpFile) {
                $files["{$this->outputDir}/tests/{$this->getClassName($file)}.php"] = (string) $file;
            }
        }

        return $files;
    }

    /**
     * Removes the directories that only hold generated code, so classes for endpoints
     * that were dropped from the API don't linger.
     */
    public function removeGeneratedDirectories(): void
    {
        $directories = [
            "src/{$this->config->dtoNamespaceSuffix}",
            "src/{$this->config->requestNamespaceSuffix}",
            $this->config->factoryNamespaceSuffix,
            'tests/Requests',
            'src/Dto', // Location of the DTOs before they were renamed to DataTransferObjects
        ];

        foreach ($directories as $directory) {
            $this->removeDirectory("{$this->outputDir}/{$directory}");
        }
    }

    /**
     * @param  array<string, string>  $files  file contents keyed by path
     * @return list<string> paths that were written; existing files are skipped unless forced
     */
    public function write(array $files, bool $force): array
    {
        $written = [];

        foreach ($files as $path => $content) {
            if (file_exists($path) && ! $force) {
                continue;
            }

            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }

            file_put_contents($path, $content);
            $written[] = $path;
        }

        return $written;
    }

    private function getClassName(PhpFile $file): string
    {
        foreach ($file->getClasses() as $class) {
            return $class->getName();
        }

        throw new \RuntimeException('No class found in PhpFile');
    }

    /**
     * The last namespace segment, e.g. "Articles" from "Sample\Sdk\Requests\Articles"
     */
    private function getRequestSubdirectory(PhpFile $file): string
    {
        foreach ($file->getNamespaces() as $namespace) {
            $parts = explode('\\', $namespace->getName());

            return end($parts);
        }

        return 'Misc';
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }
}
