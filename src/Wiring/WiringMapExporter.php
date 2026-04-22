<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Wiring;

use HelgeSverre\Toon\Toon;
use Ineersa\AiIndex\Config\IndexConfig;
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final readonly class WiringMapExporter
{
    public function __construct(
        private SymfonyContainerBuilderFactory $containerBuilderFactory,
        private WiringReferenceExtractor $referenceExtractor,
    ) {
    }

    /**
     * @return array{
     *   outputPath: string,
     *   classCount: int,
     *   definitionCount: int,
     *   aliasCount: int,
     *   injectedEdgeCount: int,
     *   dryRun: bool
     * }
     */
    public function export(IndexConfig $config, ?string $outputPathOverride = null, bool $dryRun = false): array
    {
        $outputPath = null !== $outputPathOverride && '' !== trim($outputPathOverride)
            ? $this->normalizePath($outputPathOverride, $config->projectRoot)
            : (string) $config->wiring['outputPath'];

        $container = $this->containerBuilderFactory->create($config);

        $aliases = $container->getAliases();
        $definitions = $container->getDefinitions();

        /** @var array<string, string> $classByService */
        $classByService = [];
        /** @var array<string, list<array<string, mixed>>> $serviceDefinitionsByClass */
        $serviceDefinitionsByClass = [];
        /** @var array<string, list<array<string, string>>> $aliasesByClass */
        $aliasesByClass = [];
        /** @var array<string, list<array<string, int|string>>> $injectedIntoByClass */
        $injectedIntoByClass = [];

        /** @var array<string, array{file: string, line?: int}|null> $classLocationCache */
        $classLocationCache = [];

        foreach ($definitions as $serviceId => $definition) {
            if (!$this->isEligibleServiceId($serviceId) || $definition->isAbstract()) {
                continue;
            }

            $className = $this->resolveDefinitionClass($serviceId, $definition, $container);
            if (null === $className || !$this->isProjectClass($className, $config->projectNamespacePrefix)) {
                continue;
            }

            $classByService[$serviceId] = $className;

            $entry = [
                'serviceId' => $serviceId,
                'visibility' => $definition->isPublic() ? 'public' : 'private',
                'autowire' => $definition->isAutowired(),
                'autoconfigure' => $definition->isAutoconfigured(),
            ];

            $location = $this->classLocation($className, $config->projectRoot, $classLocationCache);
            if (null !== $location) {
                $entry['file'] = $location['file'];
                if (isset($location['line'])) {
                    $entry['line'] = $location['line'];
                }
            }

            $argumentReferences = $this->referenceExtractor->normalizeDefinitionArgumentReferences(
                $definition,
                $aliases,
                $className,
            );

            if ([] !== $argumentReferences) {
                $entry['args'] = $argumentReferences;
            }

            $serviceDefinitionsByClass[$className][] = $entry;
        }

        foreach ($aliases as $aliasId => $alias) {
            if (!$this->isEligibleServiceId($aliasId)) {
                continue;
            }

            $targetId = $this->referenceExtractor->resolveAliasTargetId((string) $alias, $aliases);
            $entry = [
                'serviceId' => $aliasId,
                'target' => $targetId,
            ];

            $targetClass = $classByService[$targetId] ?? ($this->isProjectClass($targetId, $config->projectNamespacePrefix) ? $targetId : null);
            if (is_string($targetClass) && $this->isProjectClass($targetClass, $config->projectNamespacePrefix)) {
                $aliasesByClass[$targetClass][] = $entry;
            }

            if ($this->isProjectClass($aliasId, $config->projectNamespacePrefix)) {
                $aliasesByClass[$aliasId][] = $entry;
            }
        }

        foreach ($definitions as $consumerServiceId => $definition) {
            if (!$this->isEligibleServiceId($consumerServiceId) || $definition->isAbstract()) {
                continue;
            }

            $consumerClass = $this->resolveDefinitionClass($consumerServiceId, $definition, $container);
            if (null === $consumerClass || !$this->isProjectClass($consumerClass, $config->projectNamespacePrefix)) {
                continue;
            }

            $references = $this->referenceExtractor->collectDefinitionReferenceIds($definition);
            if ([] === $references) {
                continue;
            }

            foreach ($references as $referenceId) {
                $targetId = $this->referenceExtractor->resolveAliasTargetId($referenceId, $aliases);
                if (!$this->isEligibleServiceId($targetId)) {
                    continue;
                }

                $providerClass = $classByService[$targetId] ?? ($this->isProjectClass($targetId, $config->projectNamespacePrefix) ? $targetId : null);
                if (!is_string($providerClass)
                    || !$this->isProjectClass($providerClass, $config->projectNamespacePrefix)
                    || $providerClass === $consumerClass
                ) {
                    continue;
                }

                $entry = ['fqcn' => $consumerClass];
                if ($consumerServiceId !== $consumerClass) {
                    $entry['serviceId'] = $consumerServiceId;
                }

                $location = $this->classLocation($consumerClass, $config->projectRoot, $classLocationCache);
                if (null !== $location) {
                    $entry['file'] = $location['file'];
                    if (isset($location['line'])) {
                        $entry['line'] = $location['line'];
                    }
                }

                $injectedIntoByClass[$providerClass][] = $entry;
            }
        }

        $allClasses = array_values(array_unique([
            ...array_keys($serviceDefinitionsByClass),
            ...array_keys($aliasesByClass),
            ...array_keys($injectedIntoByClass),
        ]));
        sort($allClasses);

        $classEntries = [];
        foreach ($allClasses as $className) {
            $entry = ['class' => $className];

            if (isset($serviceDefinitionsByClass[$className])) {
                $serviceEntries = $this->dedupeEntries($serviceDefinitionsByClass[$className]);
                usort($serviceEntries, static fn (array $left, array $right): int => $left['serviceId'] <=> $right['serviceId']);
                $entry['serviceDefinitions'] = $serviceEntries;
            }

            if (isset($aliasesByClass[$className])) {
                $aliasEntries = $this->dedupeEntries($aliasesByClass[$className]);
                usort(
                    $aliasEntries,
                    static fn (array $left, array $right): int => [$left['serviceId'], $left['target']] <=> [$right['serviceId'], $right['target']],
                );
                $entry['aliases'] = $aliasEntries;
            }

            if (isset($injectedIntoByClass[$className])) {
                $injectedEntries = $this->dedupeEntries($injectedIntoByClass[$className]);
                usort(
                    $injectedEntries,
                    static fn (array $left, array $right): int => [
                        $left['fqcn'],
                        $left['serviceId'] ?? $left['fqcn'],
                    ] <=> [
                        $right['fqcn'],
                        $right['serviceId'] ?? $right['fqcn'],
                    ],
                );
                $entry['injectedInto'] = $injectedEntries;
            }

            $classEntries[] = $entry;
        }

        $payload = [
            'spec' => (string) ($config->wiring['spec'] ?? 'agent-core.di-wiring/v1'),
            'classes' => $classEntries,
        ];

        if (!$dryRun) {
            $directory = dirname($outputPath);
            if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Could not create output directory: %s', $directory));
            }

            if (false === file_put_contents($outputPath, Toon::encode($payload))) {
                throw new \RuntimeException(sprintf('Failed to write DI wiring map to %s', $outputPath));
            }
        }

        $definitionCount = array_sum(array_map(static fn (array $entries): int => count($entries), $serviceDefinitionsByClass));
        $aliasCount = array_sum(array_map(static fn (array $entries): int => count($entries), $aliasesByClass));
        $injectedEdgeCount = array_sum(array_map(static fn (array $entries): int => count($entries), $injectedIntoByClass));

        return [
            'outputPath' => $outputPath,
            'classCount' => count($classEntries),
            'definitionCount' => $definitionCount,
            'aliasCount' => $aliasCount,
            'injectedEdgeCount' => $injectedEdgeCount,
            'dryRun' => $dryRun,
        ];
    }

    private function normalizePath(string $path, string $projectRoot): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $projectRoot.'/'.ltrim($path, '/');
    }

    private function isEligibleServiceId(string $serviceId): bool
    {
        if ('' === $serviceId) {
            return false;
        }

        if (str_starts_with($serviceId, '.')) {
            return false;
        }

        if (str_starts_with($serviceId, 'test.')) {
            return false;
        }

        return true;
    }

    private function isProjectClass(string $className, string $projectNamespacePrefix): bool
    {
        $prefix = rtrim($projectNamespacePrefix, '\\');
        if ('' === $prefix) {
            return str_contains($className, '\\');
        }

        return str_starts_with($className, $prefix.'\\') || $className === $prefix;
    }

    /**
     * @param array<string, array{file: string, line?: int}|null> $cache
     *
     * @return array{file: string, line?: int}|null
     */
    private function classLocation(string $className, string $projectRoot, array &$cache): ?array
    {
        if (array_key_exists($className, $cache)) {
            return $cache[$className];
        }

        if (!class_exists($className) && !interface_exists($className) && !trait_exists($className) && (!function_exists('enum_exists') || !enum_exists($className))) {
            $cache[$className] = null;

            return null;
        }

        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException) {
            $cache[$className] = null;

            return null;
        }

        $file = $reflection->getFileName();
        if (false === $file) {
            $cache[$className] = null;

            return null;
        }

        $location = ['file' => $this->toProjectRelativePath($file, $projectRoot)];

        $line = $reflection->getStartLine();
        if (is_int($line) && $line > 0) {
            $location['line'] = $line;
        }

        $cache[$className] = $location;

        return $location;
    }

    private function toProjectRelativePath(string $path, string $projectRoot): string
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $normalizedRoot = rtrim(str_replace('\\', '/', $projectRoot), '/').'/';

        if (str_starts_with($normalizedPath, $normalizedRoot)) {
            return substr($normalizedPath, strlen($normalizedRoot));
        }

        return $path;
    }

    /**
     * @template T of array<string, mixed>
     *
     * @param list<T> $entries
     *
     * @return list<T>
     */
    private function dedupeEntries(array $entries): array
    {
        $deduped = [];

        foreach ($entries as $entry) {
            $key = json_encode($entry, JSON_THROW_ON_ERROR);
            $deduped[$key] = $entry;
        }

        return array_values($deduped);
    }

    private function resolveDefinitionClass(string $serviceId, Definition $definition, ContainerBuilder $container): ?string
    {
        $className = $definition->getClass();

        if (is_string($className) && '' !== trim($className)) {
            try {
                $resolved = $container->getParameterBag()->resolveValue($className);
                if (is_string($resolved) && '' !== trim($resolved)) {
                    $className = $resolved;
                }
            } catch (\Throwable) {
                // Keep unresolved class literal if container parameters cannot resolve it.
            }
        }

        if (!is_string($className) || '' === trim($className)) {
            if (!str_contains($serviceId, '\\')) {
                return null;
            }

            $className = $serviceId;
        }

        $className = ltrim($className, '\\');

        if (!str_contains($className, '\\')) {
            return null;
        }

        return $className;
    }
}
