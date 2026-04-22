<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Discovery;

use Ineersa\AiIndex\Config\IndexConfig;

final class PhpTargetResolver
{
    /**
     * @param list<string> $targets
     *
     * @return list<string>
     */
    public function resolve(IndexConfig $config, bool $all, bool $changed, array $targets): array
    {
        if (!$all && !$changed && [] === $targets) {
            $changed = true;
        }

        $phpFiles = [];

        if ($all) {
            $phpFiles = $this->collectPhpFiles($config->sourceDirectoryPath());
        } elseif ($changed) {
            $phpFiles = $this->collectChangedPhpFiles($config);
        } else {
            $phpFiles = $this->collectFromTargets($targets, $config->projectRoot);
        }

        $phpFiles = array_values(array_unique(array_filter(
            $phpFiles,
            static fn (string $path): bool => is_file($path) && str_ends_with($path, '.php'),
        )));

        sort($phpFiles);

        return $phpFiles;
    }

    /**
     * @return list<string>
     */
    private function collectPhpFiles(string $rootDirectory): array
    {
        if (!is_dir($rootDirectory)) {
            return [];
        }

        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($rootDirectory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo) {
                continue;
            }

            if ('php' !== $file->getExtension()) {
                continue;
            }

            $path = $file->getPathname();
            $resolved = realpath($path);
            if (false === $resolved) {
                continue;
            }

            $files[] = $resolved;
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function collectChangedPhpFiles(IndexConfig $config): array
    {
        $sourceDirectory = $config->sourceDirectoryRelativePath();
        $sourceSpec = rtrim($sourceDirectory, '/').'/';

        $commands = [
            sprintf(
                'git -C %s diff --name-only --diff-filter=ACMR HEAD -- %s 2>/dev/null',
                escapeshellarg($config->projectRoot),
                escapeshellarg($sourceSpec),
            ),
            sprintf(
                'git -C %s diff --name-only --diff-filter=ACMR --cached -- %s 2>/dev/null',
                escapeshellarg($config->projectRoot),
                escapeshellarg($sourceSpec),
            ),
            sprintf(
                'git -C %s ls-files --others --exclude-standard -- %s 2>/dev/null',
                escapeshellarg($config->projectRoot),
                escapeshellarg($sourceSpec),
            ),
        ];

        $files = [];

        foreach ($commands as $command) {
            $output = shell_exec($command);
            if (null === $output || '' === trim($output)) {
                continue;
            }

            foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
                if ('' === $line) {
                    continue;
                }

                $candidate = $config->projectRoot.'/'.ltrim($line, '/');
                if (!is_file($candidate) || !str_ends_with($candidate, '.php')) {
                    continue;
                }

                $resolved = realpath($candidate);
                if (false === $resolved) {
                    continue;
                }

                $files[] = $resolved;
            }
        }

        return $files;
    }

    /**
     * @param list<string> $targets
     *
     * @return list<string>
     */
    private function collectFromTargets(array $targets, string $projectRoot): array
    {
        $files = [];

        foreach ($targets as $target) {
            $resolvedTarget = $this->resolveTargetPath($target, $projectRoot);
            if (null === $resolvedTarget) {
                continue;
            }

            if (is_dir($resolvedTarget)) {
                $files = [...$files, ...$this->collectPhpFiles($resolvedTarget)];
                continue;
            }

            if (is_file($resolvedTarget) && str_ends_with($resolvedTarget, '.php')) {
                $files[] = $resolvedTarget;
            }
        }

        return $files;
    }

    private function resolveTargetPath(string $target, string $projectRoot): ?string
    {
        $absoluteTarget = realpath($target);
        if (false !== $absoluteTarget) {
            return $absoluteTarget;
        }

        $projectRelativeTarget = realpath($projectRoot.'/'.ltrim($target, '/'));
        if (false !== $projectRelativeTarget) {
            return $projectRelativeTarget;
        }

        return null;
    }
}
