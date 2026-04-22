<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Command;

use Ineersa\AiIndex\Config\ConfigLoader;
use Ineersa\AiIndex\Wiring\SymfonyContainerBuilderFactory;
use Ineersa\AiIndex\Wiring\WiringMapExporter;
use Ineersa\AiIndex\Wiring\WiringReferenceExtractor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'wiring:export',
    description: 'Export Symfony DI wiring map.',
)]
final class ExportWiringCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('project-root', null, InputOption::VALUE_REQUIRED, 'Project root directory', getcwd() ?: '.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Wiring TOON output path override')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show actions without writing files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRootInput = (string) $input->getOption('project-root');
        $projectRoot = rtrim(realpath($projectRootInput) ?: $projectRootInput, '/');
        $dryRun = (bool) $input->getOption('dry-run');

        if (!is_dir($projectRoot)) {
            $output->writeln(sprintf('<error>Project root does not exist: %s</error>', $projectRoot));

            return Command::FAILURE;
        }

        $config = (new ConfigLoader())->load($projectRoot);

        $outputPathOverride = (string) ($input->getOption('output') ?? '');
        $outputPath = '' !== trim($outputPathOverride) ? $outputPathOverride : null;

        $exporter = new WiringMapExporter(
            new SymfonyContainerBuilderFactory(),
            new WiringReferenceExtractor(),
        );

        try {
            $result = $exporter->export(
                config: $config,
                outputPathOverride: $outputPath,
                dryRun: $dryRun,
            );
        } catch (\Throwable $exception) {
            $output->writeln(sprintf('<error>wiring-map: failed - %s</error>', $exception->getMessage()));

            return Command::FAILURE;
        }

        $outputPathDisplay = self::relativePath($projectRoot, (string) $result['outputPath']);

        if ($dryRun) {
            $output->writeln(sprintf(
                'wiring-map: ok (dry-run, output=%s, classes=%d)',
                $outputPathDisplay,
                (int) $result['classCount'],
            ));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            'wiring-map: ok (classes=%d,service_definitions=%d,aliases=%d,injected_edges=%d,output=%s)',
            (int) $result['classCount'],
            (int) $result['definitionCount'],
            (int) $result['aliasCount'],
            (int) $result['injectedEdgeCount'],
            $outputPathDisplay,
        ));

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
}
