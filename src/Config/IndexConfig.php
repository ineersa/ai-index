<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Config;

final readonly class IndexConfig
{
    /**
     * @param array{outputPath: string, phpstanBin: string, configPath: string} $callGraph
     * @param array{outputPath: string, kernelFactory: null|callable|string, environment: string, debug: bool, spec: string} $wiring
     * @param array{file: string, namespace: string} $indexSpec
     */
    public function __construct(
        public string $projectRoot,
        public string $srcDir,
        public string $projectNamespacePrefix,
        public array $callGraph,
        public array $wiring,
        public array $indexSpec,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(string $projectRoot, array $config = []): self
    {
        $root = rtrim(realpath($projectRoot) ?: $projectRoot, '/');

        $defaults = [
            'srcDir' => 'src',
            'projectNamespacePrefix' => 'App\\',
            'callGraph' => [
                'outputPath' => $root.'/callgraph.json',
                'phpstanBin' => $root.'/vendor/bin/phpstan',
                'configPath' => $root.'/vendor/ineersa/call-graph/callgraph.neon',
            ],
            'wiring' => [
                'outputPath' => $root.'/var/reports/di-wiring.toon',
                'kernelFactory' => null,
                'environment' => 'test',
                'debug' => false,
                'spec' => 'agent-core.di-wiring/v1',
            ],
            'index' => [
                'spec' => [
                    'file' => 'agent-core.file-index/v1',
                    'namespace' => 'agent-core.ai-docs/v1',
                ],
            ],
        ];

        $merged = array_replace_recursive($defaults, $config);

        $kernelFactory = $merged['wiring']['kernelFactory'] ?? null;
        if (null !== $kernelFactory && !is_string($kernelFactory) && !is_callable($kernelFactory)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid wiring.kernelFactory type: %s. Expected string, callable or null.',
                get_debug_type($kernelFactory),
            ));
        }

        return new self(
            projectRoot: $root,
            srcDir: (string) $merged['srcDir'],
            projectNamespacePrefix: (string) $merged['projectNamespacePrefix'],
            callGraph: [
                'outputPath' => self::normalizePath((string) $merged['callGraph']['outputPath'], $root),
                'phpstanBin' => self::normalizePath((string) $merged['callGraph']['phpstanBin'], $root),
                'configPath' => self::normalizePath((string) $merged['callGraph']['configPath'], $root),
            ],
            wiring: [
                'outputPath' => self::normalizePath((string) $merged['wiring']['outputPath'], $root),
                'kernelFactory' => $kernelFactory,
                'environment' => (string) ($merged['wiring']['environment'] ?? 'test'),
                'debug' => (bool) ($merged['wiring']['debug'] ?? false),
                'spec' => (string) ($merged['wiring']['spec'] ?? 'agent-core.di-wiring/v1'),
            ],
            indexSpec: [
                'file' => (string) $merged['index']['spec']['file'],
                'namespace' => (string) $merged['index']['spec']['namespace'],
            ],
        );
    }

    public function sourceDirectoryPath(): string
    {
        return self::normalizePath($this->srcDir, $this->projectRoot);
    }

    public function sourceDirectoryRelativePath(): string
    {
        $sourceDir = $this->sourceDirectoryPath();
        $projectPrefix = $this->projectRoot.'/';

        if (str_starts_with($sourceDir, $projectPrefix)) {
            return ltrim(substr($sourceDir, strlen($projectPrefix)), '/');
        }

        return ltrim($this->srcDir, '/');
    }

    private static function normalizePath(string $path, string $projectRoot): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $projectRoot.'/'.ltrim($path, '/');
    }
}
