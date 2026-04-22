<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Index;

use HelgeSverre\Toon\Toon;
use Ineersa\AiIndex\Config\IndexConfig;

final class NamespaceIndexRegenerator
{
    /**
     * @return list<string>
     */
    public function regenerate(IndexConfig $config, bool $dryRun): array
    {
        $sourceDirectory = $config->sourceDirectoryPath();
        if (!is_dir($sourceDirectory)) {
            return [];
        }

        $actions = [];

        /** @var array<string, list<array{file: string, type: string}>> $namespaces */
        $namespaces = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDirectory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || 'toon' !== $file->getExtension()) {
                continue;
            }

            $path = $file->getPathname();
            if (!str_contains($path, '/docs/')) {
                continue;
            }

            $decoded = $this->decodeToArray($path);
            if (null === $decoded) {
                continue;
            }

            if ((string) ($decoded['spec'] ?? '') !== (string) $config->indexSpec['file']) {
                continue;
            }

            $namespaceDirectory = realpath(dirname($path, 2));
            if (false === $namespaceDirectory) {
                continue;
            }

            $namespaces[$namespaceDirectory][] = [
                'file' => basename($path, '.toon').'.php',
                'type' => is_string($decoded['type'] ?? null) ? (string) $decoded['type'] : '',
            ];
        }

        foreach ($namespaces as $directory => $entries) {
            $indexPath = $directory.'/ai-index.toon';

            $existingIndex = is_file($indexPath)
                ? $this->stripLegacyMetadata($this->decodeToArray($indexPath) ?? [])
                : null;

            $relativeDirectory = self::sourceRelativeDirectory($sourceDirectory, $directory);
            $namespaceName = basename($directory);
            $existingFqcn = '';
            $description = '';

            if (is_array($existingIndex)) {
                $namespaceName = is_string($existingIndex['namespace'] ?? null)
                    ? (string) $existingIndex['namespace']
                    : $namespaceName;
                $existingFqcn = is_string($existingIndex['fqcn'] ?? null)
                    ? (string) $existingIndex['fqcn']
                    : '';
                $description = is_string($existingIndex['description'] ?? null)
                    ? (string) $existingIndex['description']
                    : '';
            }

            $derivedFqcn = $this->deriveFqcnFromSourceRelativeDirectory($relativeDirectory, $config->projectNamespacePrefix);
            $fqcn = $this->isUsableExistingFqcn($existingFqcn, $config->projectNamespacePrefix)
                ? $existingFqcn
                : $derivedFqcn;

            usort(
                $entries,
                static fn (array $left, array $right): int => $left['file'] <=> $right['file'],
            );

            /** @var list<array<string, mixed>> $subNamespaces */
            $subNamespaces = (is_array($existingIndex) && isset($existingIndex['subNamespaces']) && is_array($existingIndex['subNamespaces']))
                ? $existingIndex['subNamespaces']
                : [];

            $newIndex = [
                'spec' => (string) $config->indexSpec['namespace'],
                'namespace' => $namespaceName,
                'fqcn' => $fqcn,
                'updatedAt' => date('Y-m-d'),
                'files' => $entries,
            ];

            if ('' !== trim($description)) {
                $newIndex['description'] = $description;
            }

            if ([] !== $subNamespaces) {
                $newIndex['subNamespaces'] = $subNamespaces;
            }

            $relativePath = self::relativePath($config->projectRoot, $indexPath);
            if ($dryRun) {
                $actions[] = sprintf('[DRY-RUN] would update: %s', $relativePath);
            } else {
                if (false === file_put_contents($indexPath, Toon::encode($newIndex))) {
                    throw new \RuntimeException(sprintf('Failed to write namespace index at %s', $indexPath));
                }

                $actions[] = sprintf('updated: %s', $relativePath);
            }
        }

        $parentDirectories = [];
        foreach (array_keys($namespaces) as $directory) {
            $parent = dirname($directory);
            while (str_starts_with($parent, $sourceDirectory)) {
                $parentDirectories[$parent] = true;
                $next = dirname($parent);
                if ($next === $parent) {
                    break;
                }

                $parent = $next;
            }
        }

        foreach (array_keys($parentDirectories) as $directory) {
            $indexPath = $directory.'/ai-index.toon';
            if (!is_file($indexPath)) {
                continue;
            }

            $data = $this->stripLegacyMetadata($this->decodeToArray($indexPath) ?? []);
            $data['updatedAt'] = date('Y-m-d');

            $relativePath = self::relativePath($config->projectRoot, $indexPath);
            if ($dryRun) {
                $actions[] = sprintf('[DRY-RUN] would touch: %s', $relativePath);
            } else {
                if (false === file_put_contents($indexPath, Toon::encode($data))) {
                    throw new \RuntimeException(sprintf('Failed to touch namespace index at %s', $indexPath));
                }

                $actions[] = sprintf('touched: %s', $relativePath);
            }
        }

        return $actions;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeToArray(string $path): ?array
    {
        try {
            $decoded = Toon::decode((string) file_get_contents($path));
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $index
     *
     * @return array<string, mixed>
     */
    private function stripLegacyMetadata(array $index): array
    {
        unset($index['indexedAt'], $index['indexedCommit'], $index['sourceHash']);

        return $index;
    }

    private function deriveFqcnFromSourceRelativeDirectory(string $relativeDirectory, string $projectNamespacePrefix): string
    {
        $prefix = rtrim($projectNamespacePrefix, '\\');
        $normalized = trim($relativeDirectory, '/');

        if ('' === $normalized || '.' === $normalized) {
            return $prefix;
        }

        $parts = array_values(array_filter(
            explode('/', $normalized),
            static fn (string $part): bool => '' !== $part,
        ));

        if ([] === $parts) {
            return $prefix;
        }

        if ('' === $prefix) {
            return implode('\\', $parts);
        }

        return $prefix.'\\'.implode('\\', $parts);
    }

    private function isUsableExistingFqcn(string $fqcn, string $projectNamespacePrefix): bool
    {
        if ('' === trim($fqcn)) {
            return false;
        }

        $prefix = rtrim($projectNamespacePrefix, '\\');
        if ('' !== $prefix && !str_starts_with($fqcn, $prefix)) {
            return false;
        }

        if ('' !== $prefix && ($fqcn === $prefix.'\\src' || str_contains($fqcn, '\\src\\'))) {
            return false;
        }

        return true;
    }

    private static function sourceRelativeDirectory(string $sourceDirectory, string $path): string
    {
        $normalizedSourceDirectory = rtrim($sourceDirectory, '/');
        $normalizedPath = rtrim($path, '/');

        if ($normalizedPath === $normalizedSourceDirectory) {
            return '';
        }

        $prefix = $normalizedSourceDirectory.'/';
        if (str_starts_with($normalizedPath, $prefix)) {
            return substr($normalizedPath, strlen($prefix));
        }

        return $normalizedPath;
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
