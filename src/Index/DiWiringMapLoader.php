<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Index;

use HelgeSverre\Toon\Toon;

final class DiWiringMapLoader
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function loadByClass(string $wiringPath): array
    {
        if (!is_file($wiringPath)) {
            return [];
        }

        try {
            $payload = Toon::decode((string) file_get_contents($wiringPath));
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($payload)) {
            return [];
        }

        $entries = $payload['classes'] ?? [];
        if (!is_array($entries)) {
            return [];
        }

        $byClass = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $className = is_string($entry['class'] ?? null)
                ? trim((string) $entry['class'])
                : '';

            if ('' === $className) {
                continue;
            }

            $wiring = [];

            $serviceDefinitions = $entry['serviceDefinitions'] ?? null;
            if (is_array($serviceDefinitions) && [] !== $serviceDefinitions) {
                $wiring['serviceDefinitions'] = $serviceDefinitions;
            }

            $aliases = $entry['aliases'] ?? null;
            if (is_array($aliases) && [] !== $aliases) {
                $wiring['aliases'] = $aliases;
            }

            $injectedInto = $entry['injectedInto'] ?? null;
            if (is_array($injectedInto) && [] !== $injectedInto) {
                $wiring['injectedInto'] = $injectedInto;
            }

            if ([] !== $wiring) {
                $byClass[$className] = $wiring;
            }
        }

        ksort($byClass);

        return $byClass;
    }
}
