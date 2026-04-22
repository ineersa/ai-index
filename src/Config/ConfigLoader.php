<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Config;

final class ConfigLoader
{
    public function __construct(
        private readonly string $configFileName = '.ai-index.php',
    ) {
    }

    public function load(string $projectRoot): IndexConfig
    {
        $configPath = rtrim($projectRoot, '/').'/'.$this->configFileName;

        if (!is_file($configPath)) {
            return IndexConfig::fromArray($projectRoot);
        }

        $config = require $configPath;

        if (!is_array($config)) {
            throw new \RuntimeException(sprintf(
                'Invalid AI index config at %s. Expected array, got %s.',
                $configPath,
                get_debug_type($config),
            ));
        }

        /** @var array<string, mixed> $config */
        return IndexConfig::fromArray($projectRoot, $config);
    }
}
