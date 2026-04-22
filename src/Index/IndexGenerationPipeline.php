<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Index;

use Ineersa\AiIndex\CallGraph\CallGraphGenerator;
use Ineersa\AiIndex\CallGraph\CallGraphLoader;
use Ineersa\AiIndex\Config\IndexConfig;
use Ineersa\AiIndex\Discovery\PhpTargetResolver;

final readonly class IndexGenerationPipeline
{
    public function __construct(
        private PhpTargetResolver $targetResolver,
        private CallGraphGenerator $callGraphGenerator,
        private CallGraphLoader $callGraphLoader,
        private DiWiringMapLoader $diWiringMapLoader,
        private ClassIndexBuilder $classIndexBuilder,
        private FileIndexWriter $fileIndexWriter,
        private NamespaceIndexRegenerator $namespaceIndexRegenerator,
    ) {
    }

    /**
     * @param list<string> $targets
     * @param null|array<string, array<string, mixed>> $diWiringByClassOverride
     *
     * @return array{
     *   files: list<string>,
     *   callGraph: array<string, array<string, array{callers: list<string>, callees: list<string>}>>,
     *   callGraphStep: array{status: string, message: string, command: null|string, exitCode: null|int},
     *   actions: list<string>,
     *   namespaceActions: list<string>,
     *   generated: int,
     *   skipped: int,
     *   warnings: list<string>
     * }
     */
    public function run(
        IndexConfig $config,
        bool $all,
        bool $changed,
        array $targets,
        bool $force,
        bool $dryRun,
        bool $skipNamespace,
        ?array $diWiringByClassOverride = null,
    ): array {
        $phpFiles = $this->targetResolver->resolve($config, $all, $changed, $targets);

        if ([] === $phpFiles) {
            return [
                'files' => [],
                'callGraph' => [],
                'callGraphStep' => [
                    'status' => 'skipped',
                    'message' => 'No PHP files to process.',
                    'command' => null,
                    'exitCode' => null,
                ],
                'actions' => [],
                'namespaceActions' => [],
                'generated' => 0,
                'skipped' => 0,
                'warnings' => [],
            ];
        }

        $callGraphStep = $this->callGraphGenerator->generate($config, $dryRun);

        if ('failed' === $callGraphStep['status']) {
            return [
                'files' => $phpFiles,
                'callGraph' => [],
                'callGraphStep' => $callGraphStep,
                'actions' => [],
                'namespaceActions' => [],
                'generated' => 0,
                'skipped' => 0,
                'warnings' => [],
            ];
        }

        $callGraph = [];
        if (!$dryRun) {
            $callGraph = $this->callGraphLoader->load(
                (string) $config->callGraph['outputPath'],
                $config->projectNamespacePrefix,
                (bool) ($config->callGraph['includeVendorEdges'] ?? false),
            );
        }

        $warnings = [];
        $wiringPath = (string) $config->wiring['outputPath'];

        if (is_array($diWiringByClassOverride)) {
            $diWiringByClass = $diWiringByClassOverride;
        } else {
            $diWiringByClass = $this->diWiringMapLoader->loadByClass($wiringPath);

            if ([] === $diWiringByClass) {
                $warnings[] = sprintf(
                    'No DI wiring map found at %s; wiring metadata will be omitted.',
                    $wiringPath,
                );
            }
        }

        $actions = [];
        $generated = 0;
        $skipped = 0;

        foreach ($phpFiles as $phpFile) {
            $outputPath = dirname($phpFile).'/docs/'.basename($phpFile, '.php').'.toon';

            if ($this->fileIndexWriter->isUpToDate($phpFile, $outputPath, $force)) {
                $actions[] = $this->fileIndexWriter->skipMessage($phpFile, $config->projectRoot, 'up to date');
                ++$skipped;
                continue;
            }

            $code = file_get_contents($phpFile);
            if (false === $code) {
                $actions[] = $this->fileIndexWriter->skipMessage($phpFile, $config->projectRoot, 'unable to read file');
                ++$skipped;
                continue;
            }

            $buildResult = $this->classIndexBuilder->build(
                phpFile: $phpFile,
                code: $code,
                callGraph: $callGraph,
                diWiringByClass: $diWiringByClass,
                config: $config,
            );

            $skipReason = $buildResult['skipReason'];
            if (is_string($skipReason)) {
                $actions[] = $this->fileIndexWriter->skipMessage($phpFile, $config->projectRoot, $skipReason);
                ++$skipped;
                continue;
            }

            /** @var list<array<string, mixed>> $entries */
            $entries = $buildResult['entries'];
            foreach ($entries as $entry) {
                $actions[] = $this->fileIndexWriter->write($outputPath, $entry, $dryRun, $config->projectRoot);
                ++$generated;
            }
        }

        $namespaceActions = [];
        if (!$skipNamespace && $generated > 0) {
            $namespaceActions = $this->namespaceIndexRegenerator->regenerate($config, $dryRun);
        }

        return [
            'files' => $phpFiles,
            'callGraph' => $callGraph,
            'callGraphStep' => $callGraphStep,
            'actions' => $actions,
            'namespaceActions' => $namespaceActions,
            'generated' => $generated,
            'skipped' => $skipped,
            'warnings' => $warnings,
        ];
    }
}
