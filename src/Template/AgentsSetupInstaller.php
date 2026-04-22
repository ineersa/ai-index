<?php

declare(strict_types=1);

namespace Ineersa\AiIndex\Template;

final readonly class AgentsSetupInstaller
{
    private const SECTION_BEGIN = '<!-- ai-index:begin -->';
    private const SECTION_END = '<!-- ai-index:end -->';

    public function __construct(
        private string $templateRoot,
    ) {
    }

    /**
     * @return list<string>
     */
    public function install(string $projectRoot, bool $dryRun = false, bool $force = false): array
    {
        $actions = [];

        $actions[] = $this->copyTemplate(
            projectRoot: $projectRoot,
            relativePath: '.agents/skills/ai-index/SKILL.md',
            dryRun: $dryRun,
            force: $force,
        );

        $actions[] = $this->copyTemplate(
            projectRoot: $projectRoot,
            relativePath: '.agents/index-maintainer.md',
            dryRun: $dryRun,
            force: $force,
        );

        $actions[] = $this->copyTemplate(
            projectRoot: $projectRoot,
            relativePath: '.pi/extensions/ai-index-watch.ts',
            dryRun: $dryRun,
            force: $force,
        );

        $actions[] = $this->upsertAgentsSection($projectRoot, $dryRun);

        return $actions;
    }

    private function copyTemplate(string $projectRoot, string $relativePath, bool $dryRun, bool $force): string
    {
        $source = $this->templateRoot.'/'.$relativePath;
        $destination = rtrim($projectRoot, '/').'/'.$relativePath;

        if (!is_file($source)) {
            throw new \RuntimeException(sprintf('Template file not found: %s', $source));
        }

        $destinationExists = is_file($destination);

        if ($destinationExists && !$force) {
            return sprintf('skip %s (already exists, use --force to overwrite)', $relativePath);
        }

        $action = $destinationExists ? 'overwrite' : 'copy';

        if ($dryRun) {
            return sprintf('dry-run: would %s %s', $action, $relativePath);
        }

        $destinationDir = dirname($destination);
        if (!is_dir($destinationDir) && !mkdir($destinationDir, 0777, true) && !is_dir($destinationDir)) {
            throw new \RuntimeException(sprintf('Failed to create directory: %s', $destinationDir));
        }

        if (!copy($source, $destination)) {
            throw new \RuntimeException(sprintf('Failed to copy %s to %s', $source, $destination));
        }

        return sprintf('%s %s', $action, $relativePath);
    }

    private function upsertAgentsSection(string $projectRoot, bool $dryRun): string
    {
        $sectionTemplatePath = $this->templateRoot.'/AGENTS.section.md';
        if (!is_file($sectionTemplatePath)) {
            throw new \RuntimeException(sprintf('Template file not found: %s', $sectionTemplatePath));
        }

        $sectionBody = trim((string) file_get_contents($sectionTemplatePath));
        $sectionBlock = self::SECTION_BEGIN."\n".$sectionBody."\n".self::SECTION_END;

        $agentsPath = rtrim($projectRoot, '/').'/AGENTS.md';
        $agentsExists = is_file($agentsPath);
        $existing = $agentsExists
            ? (string) file_get_contents($agentsPath)
            : "# AGENTS\n\n";

        $pattern = '/'.preg_quote(self::SECTION_BEGIN, '/').'.*?'.preg_quote(self::SECTION_END, '/').'/s';

        if (1 === preg_match($pattern, $existing)) {
            $updated = preg_replace($pattern, $sectionBlock, $existing, 1, $replacements);
            if (null === $updated || 1 !== $replacements) {
                throw new \RuntimeException('Failed to update ai-index section in AGENTS.md.');
            }
            $action = 'update ai-index section in AGENTS.md';
        } else {
            $prefix = rtrim($existing);
            $separator = '' === $prefix ? '' : "\n\n";
            $updated = $prefix.$separator.$sectionBlock."\n";
            $action = $agentsExists
                ? 'append ai-index section to AGENTS.md'
                : 'create AGENTS.md with ai-index section';
        }

        if ($updated === $existing) {
            return 'AGENTS.md ai-index section is already up to date';
        }

        if ($dryRun) {
            return 'dry-run: would '.$action;
        }

        if (!is_dir(dirname($agentsPath)) && !mkdir(dirname($agentsPath), 0777, true) && !is_dir(dirname($agentsPath))) {
            throw new \RuntimeException(sprintf('Failed to create directory for AGENTS.md at %s', dirname($agentsPath)));
        }

        if (false === file_put_contents($agentsPath, $updated)) {
            throw new \RuntimeException(sprintf('Failed to write AGENTS.md at %s', $agentsPath));
        }

        return $action;
    }
}
