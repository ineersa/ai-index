<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\CallGraph;

final class CallGraphLoader
{
    /**
     * @return array<string, array<string, array{callers: list<string>, callees: list<string>}>>
     */
    public function load(string $path, string $projectNamespacePrefix = ''): array
    {
        if (!is_file($path)) {
            return [];
        }

        $raw = (string) file_get_contents($path);
        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            return [];
        }

        $edges = $decoded['edges'] ?? [];
        if (!is_array($edges)) {
            return [];
        }

        $namespacePrefix = rtrim($projectNamespacePrefix, '\\');
        if ('' !== $namespacePrefix) {
            $namespacePrefix .= '\\';
        }

        $map = [];

        foreach ($edges as $edge) {
            if (!is_array($edge)) {
                continue;
            }

            if ('function' === ($edge['calleeKind'] ?? null) || 'function' === ($edge['callerKind'] ?? null)) {
                continue;
            }

            if (true === ($edge['unresolved'] ?? false)) {
                continue;
            }

            $callerClass = (string) ($edge['callerClass'] ?? '');
            $callerMethod = (string) ($edge['callerMember'] ?? '');
            $calleeClass = (string) ($edge['calleeClass'] ?? '');
            $calleeMethod = (string) ($edge['calleeMember'] ?? '');

            if ('' === $callerClass || '' === $callerMethod || '' === $calleeClass || '' === $calleeMethod) {
                continue;
            }

            if ('' !== $namespacePrefix
                && (!str_starts_with($callerClass, $namespacePrefix) || !str_starts_with($calleeClass, $namespacePrefix))
            ) {
                continue;
            }

            $map[$callerClass][$callerMethod]['callees'][] = $calleeClass.'::'.$calleeMethod;
            $map[$calleeClass][$calleeMethod]['callers'][] = $callerClass.'::'.$callerMethod;
        }

        foreach ($map as $className => $methods) {
            foreach ($methods as $methodName => $entry) {
                $callers = $entry['callers'] ?? [];
                $callees = $entry['callees'] ?? [];

                if (!is_array($callers) || !is_array($callees)) {
                    continue;
                }

                $callers = array_values(array_unique(array_filter($callers, static fn (mixed $value): bool => is_string($value) && '' !== trim($value))));
                $callees = array_values(array_unique(array_filter($callees, static fn (mixed $value): bool => is_string($value) && '' !== trim($value))));

                sort($callers);
                sort($callees);

                $map[$className][$methodName] = [
                    'callers' => $callers,
                    'callees' => $callees,
                ];
            }

            ksort($map[$className]);
        }

        ksort($map);

        return $map;
    }
}
