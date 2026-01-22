<?php

declare(strict_types=1);

namespace JsonApiSdk\Services;

use Symfony\Component\Console\Style\SymfonyStyle;

class PintRunner
{
    public function run(string $outputDir, SymfonyStyle $io): void
    {
        $io->section('Formatting Code');

        $pintPath = "{$outputDir}/vendor/bin/pint";

        if (! file_exists($pintPath)) {
            $io->warning('Pint not found. Skipping code formatting. Run "composer require --dev laravel/pint" to enable.');

            return;
        }

        $io->writeln('Running Pint...');

        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open("{$pintPath} {$outputDir}/src {$outputDir}/tests {$outputDir}/config {$outputDir}/factories", $descriptorSpec, $pipes, $outputDir);

        if (! \is_resource($process)) {
            $io->warning('Failed to start Pint process.');

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
            $io->warning("Pint formatting failed ({$exitCode}).\n{$stderr}");
        } else {
            $io->writeln('Code formatted successfully.');
        }
    }
}
