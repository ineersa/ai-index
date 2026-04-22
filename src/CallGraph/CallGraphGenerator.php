<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\CallGraph;

use Ineersa\AiIndex\Config\IndexConfig;

final class CallGraphGenerator
{
    /**
     * @return array{status: string, message: string, command: null|string, exitCode: null|int}
     */
    public function generate(IndexConfig $config, bool $dryRun = false): array
    {
        $phpstanBin = (string) ($config->callGraph['phpstanBin'] ?? '');
        $callGraphConfigPath = (string) ($config->callGraph['configPath'] ?? '');
        $sourceDir = $config->sourceDirectoryRelativePath();

        if (!is_file($callGraphConfigPath)) {
            return [
                'status' => 'failed',
                'message' => sprintf('Call graph config not found at %s.', $callGraphConfigPath),
                'command' => null,
                'exitCode' => null,
            ];
        }

        if (!is_file($phpstanBin)) {
            return [
                'status' => 'failed',
                'message' => sprintf('PHPStan binary not found at %s.', $phpstanBin),
                'command' => null,
                'exitCode' => null,
            ];
        }

        $command = sprintf(
            'cd %s && %s analyse -c %s %s --no-progress --no-ansi',
            escapeshellarg($config->projectRoot),
            escapeshellarg($phpstanBin),
            escapeshellarg($callGraphConfigPath),
            escapeshellarg($sourceDir),
        );

        if ($dryRun) {
            return [
                'status' => 'dry-run',
                'message' => 'Would generate call graph via PHPStan call-graph extension.',
                'command' => $command,
                'exitCode' => null,
            ];
        }

        $lines = [];
        $exitCode = 0;
        exec($command.' 2>&1', $lines, $exitCode);

        $output = trim(implode("\n", $lines));
        $callGraphPath = (string) ($config->callGraph['outputPath'] ?? '');

        if (0 !== $exitCode) {
            return [
                'status' => 'failed',
                'message' => sprintf(
                    'Call graph generation failed (exit=%d).%s',
                    $exitCode,
                    '' !== $output ? "\n".$output : '',
                ),
                'command' => $command,
                'exitCode' => $exitCode,
            ];
        }

        if ('' === $callGraphPath || !is_file($callGraphPath)) {
            return [
                'status' => 'failed',
                'message' => sprintf('Call graph output not found at %s after successful run.', $callGraphPath),
                'command' => $command,
                'exitCode' => $exitCode,
            ];
        }

        return [
            'status' => 'generated',
            'message' => sprintf('Call graph generated successfully at %s.', $callGraphPath),
            'command' => $command,
            'exitCode' => $exitCode,
        ];
    }
}
