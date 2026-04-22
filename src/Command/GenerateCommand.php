<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Command;

use Ineersa\AiIndex\CallGraph\CallGraphGenerator;
use Ineersa\AiIndex\CallGraph\CallGraphLoader;
use Ineersa\AiIndex\Config\ConfigLoader;
use Ineersa\AiIndex\Discovery\PhpTargetResolver;
use Ineersa\AiIndex\Index\ClassIndexBuilder;
use Ineersa\AiIndex\Index\DiWiringMapLoader;
use Ineersa\AiIndex\Index\FileIndexWriter;
use Ineersa\AiIndex\Index\IndexGenerationPipeline;
use Ineersa\AiIndex\Index\NamespaceIndexRegenerator;
use Ineersa\AiIndex\Wiring\SymfonyContainerBuilderFactory;
use Ineersa\AiIndex\Wiring\WiringMapExporter;
use Ineersa\AiIndex\Wiring\WiringReferenceExtractor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'generate',
    description: 'Generate AI class and namespace indexes.',
)]
final class GenerateCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('project-root', null, InputOption::VALUE_REQUIRED, 'Project root directory', getcwd() ?: '.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Process all PHP files under src/')
            ->addOption('changed', null, InputOption::VALUE_NONE, 'Process only git-changed files')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Regenerate even if generated files are newer')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show actions without writing files')
            ->addOption('skip-wiring', null, InputOption::VALUE_NONE, 'Skip wiring export pre-step')
            ->addOption('skip-namespace', null, InputOption::VALUE_NONE, 'Skip namespace index regeneration')
            ->addArgument('targets', InputArgument::IS_ARRAY, 'Optional target files/directories');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRootInput = (string) $input->getOption('project-root');
        $projectRoot = rtrim(realpath($projectRootInput) ?: $projectRootInput, '/');

        if (!is_dir($projectRoot)) {
            $output->writeln(sprintf('<error>Project root does not exist: %s</error>', $projectRoot));

            return Command::FAILURE;
        }

        $all = (bool) $input->getOption('all');
        $changed = (bool) $input->getOption('changed');
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $skipWiring = (bool) $input->getOption('skip-wiring');
        $skipNamespace = (bool) $input->getOption('skip-namespace');

        /** @var list<string> $targets */
        $targets = array_values(array_filter(
            (array) $input->getArgument('targets'),
            static fn (mixed $value): bool => is_string($value) && '' !== trim($value),
        ));

        $config = (new ConfigLoader())->load($projectRoot);

        $diWiringByClassOverride = null;

        if (!$skipWiring) {
            $wiringExporter = new WiringMapExporter(
                new SymfonyContainerBuilderFactory(),
                new WiringReferenceExtractor(),
            );

            try {
                $wiringResult = $wiringExporter->export(
                    config: $config,
                    outputPathOverride: null,
                    dryRun: $dryRun,
                );
            } catch (\Throwable $exception) {
                $output->writeln(sprintf('<error>wiring-map: failed - %s</error>', $exception->getMessage()));

                return Command::FAILURE;
            }

            $wiringOutputPath = self::relativePath($projectRoot, (string) $wiringResult['outputPath']);

            if ($dryRun) {
                $output->writeln(sprintf(
                    '<info>wiring-map: ok (dry-run, output=%s, classes=%d)</info>',
                    $wiringOutputPath,
                    (int) $wiringResult['classCount'],
                ));
            } else {
                $output->writeln(sprintf(
                    '<info>wiring-map: ok (classes=%d,service_definitions=%d,aliases=%d,injected_edges=%d,output=%s)</info>',
                    (int) $wiringResult['classCount'],
                    (int) $wiringResult['definitionCount'],
                    (int) $wiringResult['aliasCount'],
                    (int) $wiringResult['injectedEdgeCount'],
                    $wiringOutputPath,
                ));
            }

            /** @var array<string, array<string, mixed>> $wiringByClass */
            $wiringByClass = $wiringResult['wiringByClass'];
            $diWiringByClassOverride = $wiringByClass;
        } else {
            $output->writeln('<comment>wiring-map: skipped (--skip-wiring)</comment>');
        }

        $pipeline = new IndexGenerationPipeline(
            new PhpTargetResolver(),
            new CallGraphGenerator(),
            new CallGraphLoader(),
            new DiWiringMapLoader(),
            new ClassIndexBuilder(),
            new FileIndexWriter(),
            new NamespaceIndexRegenerator(),
        );

        $result = $pipeline->run(
            config: $config,
            all: $all,
            changed: $changed,
            targets: $targets,
            force: $force,
            dryRun: $dryRun,
            skipNamespace: $skipNamespace,
            diWiringByClassOverride: $diWiringByClassOverride,
        );

        /** @var list<string> $files */
        $files = $result['files'];

        if ([] === $files) {
            $output->writeln('<info>No PHP files to process.</info>');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Processing %d file(s)...</info>', count($files)));

        $callGraphStep = $result['callGraphStep'];
        if (is_string($callGraphStep['command']) && ($dryRun || $output->isVerbose())) {
            $output->writeln(sprintf('Call graph command: %s', $callGraphStep['command']));
        }

        $status = (string) $callGraphStep['status'];
        $message = (string) $callGraphStep['message'];
        if ('generated' === $status) {
            $output->writeln(sprintf('<info>%s</info>', $message));
        } elseif ('failed' === $status) {
            $output->writeln(sprintf('<error>%s</error>', $message));
        } elseif ('' !== $message) {
            $output->writeln(sprintf('<comment>%s</comment>', $message));
        }

        /** @var list<string> $warnings */
        $warnings = $result['warnings'];
        foreach ($warnings as $warning) {
            $output->writeln(sprintf('<comment>warning: %s</comment>', $warning));
        }

        /** @var list<string> $actions */
        $actions = $result['actions'];
        foreach ($actions as $action) {
            $output->writeln(sprintf('  %s', $action));
        }

        /** @var list<string> $namespaceActions */
        $namespaceActions = $result['namespaceActions'];
        if ([] !== $namespaceActions) {
            $output->writeln('');
            $output->writeln('--- Regenerating namespace indexes ---');

            foreach ($namespaceActions as $namespaceAction) {
                $output->writeln(sprintf('  %s', $namespaceAction));
            }
        }

        if (!$dryRun) {
            /** @var array<string, array<string, array{callers: list<string>, callees: list<string>}>> $callGraph */
            $callGraph = $result['callGraph'];
            $summary = self::summarizeCallGraph($callGraph);

            $output->writeln('');
            $output->writeln(sprintf(
                'Loaded call graph map: %d classes, %d methods, %d edges.',
                $summary['classes'],
                $summary['methods'],
                $summary['edges'],
            ));
        }

        $output->writeln('');
        $output->writeln('--- Stats ---');
        $output->writeln(sprintf('Generated: %d', (int) $result['generated']));
        $output->writeln(sprintf('Skipped:   %d', (int) $result['skipped']));

        if ($dryRun) {
            $output->writeln('');
            $output->writeln('(DRY-RUN — no files were written)');
        }

        return Command::SUCCESS;
    }

    private static function relativePath(string $projectRoot, string $path): string
    {
        $prefix = rtrim($projectRoot, '/').'/';

        if (str_starts_with($path, $prefix)) {
            return substr($path, strlen($prefix));
        }

        return $path;
    }

    /**
     * @param array<string, array<string, array{callers: list<string>, callees: list<string>}>> $map
     *
     * @return array{classes: int, methods: int, edges: int}
     */
    private static function summarizeCallGraph(array $map): array
    {
        $classCount = count($map);
        $methodCount = 0;
        $edgeCount = 0;

        foreach ($map as $methods) {
            $methodCount += count($methods);
            foreach ($methods as $entry) {
                $edgeCount += count($entry['callees']);
            }
        }

        return [
            'classes' => $classCount,
            'methods' => $methodCount,
            'edges' => $edgeCount,
        ];
    }
}
