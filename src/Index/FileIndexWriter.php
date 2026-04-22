<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Index;

use HelgeSverre\Toon\Toon;

final class FileIndexWriter
{
    public function isUpToDate(string $sourcePath, string $outputPath, bool $force): bool
    {
        if ($force) {
            return false;
        }

        if (!is_file($sourcePath) || !is_file($outputPath)) {
            return false;
        }

        $sourceMtime = filemtime($sourcePath);
        $outputMtime = filemtime($outputPath);

        if (false === $sourceMtime || false === $outputMtime) {
            return false;
        }

        return $outputMtime > $sourceMtime;
    }

    /**
     * @param array<string, mixed> $indexData
     */
    public function write(string $outputPath, array $indexData, bool $dryRun, string $projectRoot): string
    {
        $relativePath = self::relativePath($projectRoot, $outputPath);

        if ($dryRun) {
            return sprintf('[DRY-RUN] would write: %s', $relativePath);
        }

        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Failed to create docs directory: %s', $directory));
        }

        if (false === file_put_contents($outputPath, Toon::encode($indexData))) {
            throw new \RuntimeException(sprintf('Failed to write file index at %s', $outputPath));
        }

        return sprintf('wrote: %s', $relativePath);
    }

    public function skipMessage(string $sourcePath, string $projectRoot, string $reason): string
    {
        return sprintf('skip: %s (%s)', self::relativePath($projectRoot, $sourcePath), $reason);
    }

    private static function relativePath(string $projectRoot, string $path): string
    {
        $prefix = rtrim($projectRoot, '/').'/';
        if (str_starts_with($path, $prefix)) {
            return substr($path, strlen($prefix));
        }

        return $path;
    }
}
