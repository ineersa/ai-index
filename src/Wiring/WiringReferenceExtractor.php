<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Wiring;

use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\Argument\AbstractArgument;
use Symfony\Component\DependencyInjection\Argument\BoundArgument;
use Symfony\Component\DependencyInjection\Argument\IteratorArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\Argument\ServiceLocatorArgument;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class WiringReferenceExtractor
{
    /**
     * @param array<string, Alias> $aliases
     *
     * @return array<string, string>
     */
    public function normalizeDefinitionArgumentReferences(Definition $definition, array $aliases, string $className): array
    {
        $constructorParams = $this->constructorParameterNames($className);

        $normalized = [];
        foreach ($definition->getArguments() as $index => $argument) {
            $referenceIds = $this->extractReferencesFromValue($argument);
            if ([] === $referenceIds) {
                continue;
            }

            $resolvedReferences = [];
            foreach ($referenceIds as $referenceId) {
                $resolvedReferences[] = $this->resolveAliasTargetId($referenceId, $aliases);
            }

            $resolvedReferences = array_values(array_unique($resolvedReferences));
            sort($resolvedReferences);

            $argumentName = match (true) {
                is_string($index) => str_starts_with($index, '$') ? $index : '$'.$index,
                is_int($index) && isset($constructorParams[$index]) => '$'.$constructorParams[$index],
                default => '#'.(string) $index,
            };

            $normalized[$argumentName] = 1 === count($resolvedReferences)
                ? 'service('.$resolvedReferences[0].')'
                : 'services('.implode('|', $resolvedReferences).')';
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, Alias> $aliases
     */
    public function resolveAliasTargetId(string $serviceId, array $aliases): string
    {
        $current = $serviceId;
        $visited = [];

        while (isset($aliases[$current])) {
            if (isset($visited[$current])) {
                break;
            }

            $visited[$current] = true;
            $current = (string) $aliases[$current];
        }

        return $current;
    }

    /**
     * @return list<string>
     */
    public function collectDefinitionReferenceIds(Definition $definition): array
    {
        $references = [];

        $references = [...$references, ...$this->extractReferencesFromValue($definition->getArguments())];
        $references = [...$references, ...$this->extractReferencesFromValue($definition->getProperties())];
        $references = [...$references, ...$this->extractReferencesFromValue($definition->getFactory())];
        $references = [...$references, ...$this->extractReferencesFromValue($definition->getConfigurator())];

        foreach ($definition->getMethodCalls() as [, $methodArguments]) {
            $references = [...$references, ...$this->extractReferencesFromValue($methodArguments)];
        }

        $decorated = $definition->getDecoratedService();
        if (is_array($decorated) && isset($decorated[0]) && is_string($decorated[0]) && '' !== $decorated[0]) {
            $references[] = $decorated[0];
        }

        $references = array_values(array_unique($references));
        sort($references);

        return $references;
    }

    /**
     * @return list<string>
     */
    private function extractReferencesFromValue(mixed $value): array
    {
        if ($value instanceof Reference) {
            return [(string) $value];
        }

        if (
            $value instanceof IteratorArgument
            || $value instanceof TaggedIteratorArgument
            || $value instanceof ServiceLocatorArgument
            || $value instanceof ServiceClosureArgument
            || $value instanceof BoundArgument
        ) {
            return $this->extractReferencesFromValue($value->getValues());
        }

        if ($value instanceof AbstractArgument) {
            return [];
        }

        if ($value instanceof Definition) {
            return $this->collectDefinitionReferenceIds($value);
        }

        if (!is_array($value)) {
            return [];
        }

        $references = [];
        foreach ($value as $item) {
            $references = [...$references, ...$this->extractReferencesFromValue($item)];
        }

        $references = array_values(array_unique($references));
        sort($references);

        return $references;
    }

    /**
     * @return array<int, string>
     */
    private function constructorParameterNames(string $className): array
    {
        if (!class_exists($className)) {
            return [];
        }

        try {
            $reflection = new \ReflectionClass($className);
        } catch (\ReflectionException) {
            return [];
        }

        $constructor = $reflection->getConstructor();
        if (null === $constructor) {
            return [];
        }

        $names = [];
        foreach ($constructor->getParameters() as $index => $parameter) {
            $names[$index] = $parameter->getName();
        }

        return $names;
    }
}
