<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Command;

use Ineersa\AiIndex\Template\AgentsSetupInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'setup',
    description: 'Install AI index templates and AGENTS.md section in a Symfony project.',
)]
final class SetupCommand extends Command
{
    public function __construct(
        private readonly AgentsSetupInstaller $installer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('project-root', null, InputOption::VALUE_REQUIRED, 'Project root directory', getcwd() ?: '.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show planned actions without writing files')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite existing template files');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRootInput = (string) $input->getOption('project-root');
        $projectRoot = rtrim(realpath($projectRootInput) ?: $projectRootInput, '/');
        $dryRun = (bool) $input->getOption('dry-run');
        $force = (bool) $input->getOption('force');

        $output->writeln(sprintf('<info>ai-index setup</info> (project-root: %s)', $projectRoot));

        foreach ($this->installer->install($projectRoot, $dryRun, $force) as $action) {
            $output->writeln(sprintf(' - %s', $action));
        }

        return Command::SUCCESS;
    }
}
