# ineersa/ai-index

Standalone AI index tooling for Symfony projects.

It provides a CLI binary (`vendor/bin/ai-index`) to:
- install agent templates and AGENTS section (`setup`)
- export Symfony DI wiring metadata (`wiring:export`)
- generate per-class and namespace AI index files (`generate`)

---

## Status

Current status: **usable / beta**.

Implemented:
- setup recipe (idempotent)
- DI wiring export from compiled Symfony container
- class docs index generation (`src/**/docs/*.toon`)
- namespace index regeneration (`src/**/ai-index.toon`)
- optional callgraph integration (graceful fallback when unavailable)

Still recommended before first stable release:
- add unit + integration test suites
- run consumer parity checks in more projects

---

## Installation

### From Packagist (normal)

```bash
composer require --dev ineersa/ai-index
```

### Local path dependency (development)

In consumer `composer.json`:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "packages/ai-index",
      "options": { "symlink": true }
    }
  ]
}
```

Then:

```bash
composer require --dev ineersa/ai-index:@dev -W
```

---

## Quick start

From the Symfony project root:

```bash
vendor/bin/ai-index setup
vendor/bin/ai-index wiring:export
vendor/bin/ai-index generate --changed
```

For full regeneration:

```bash
vendor/bin/ai-index generate --all --force
```

---

## Commands

## `setup`

Install templates and upsert AGENTS section.

```bash
vendor/bin/ai-index setup [--project-root=...] [--dry-run] [--force]
```

Behavior:
- copies templates:
  - `.agents/skills/ai-index/SKILL.md`
  - `.agents/index-maintainer.md`
  - `.pi/extensions/ai-index-watch.ts`
- upserts `AGENTS.md` section between markers:
  - `<!-- ai-index:begin -->`
  - `<!-- ai-index:end -->`
- idempotent by default

## `wiring:export`

Export DI wiring map as Toon.

```bash
vendor/bin/ai-index wiring:export [--project-root=...] [--output=...] [--dry-run]
```

Default output: `var/reports/di-wiring.toon`.

## `generate`

Generate class docs + namespace indexes.

```bash
vendor/bin/ai-index generate [--project-root=...] [--all|--changed|<targets...>] [--force] [--dry-run] [--skip-namespace]
```

Outputs:
- `src/**/docs/*.toon`
- `src/**/ai-index.toon`

---

## Configuration (`.ai-index.php`)

Create this file in the consumer project root to override defaults.

```php
<?php

declare(strict_types=1);

return [
    'srcDir' => 'src',
    'projectNamespacePrefix' => 'App\\',

    'callGraph' => [
        'outputPath' => 'callgraph.json',
        'phpstanBin' => 'vendor/bin/phpstan',
        'configPath' => 'vendor/ineersa/call-graph/callgraph.neon',
    ],

    'wiring' => [
        'outputPath' => 'var/reports/di-wiring.toon',

        // Either a Kernel class name (recommended):
        'kernelFactory' => App\Kernel::class,

        // or a callable: fn (string $env, bool $debug, string $projectRoot) => KernelInterface

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
```

---

## Notes / troubleshooting

- Callgraph is optional. If PHPStan callgraph config is missing, generation continues without callers/callees.
- `wiring:export` needs a bootable Symfony kernel in the target project.
- `vendor/bin/ai-index` is a Composer-generated proxy. The `../ineersa/ai-index/bin/ai-index` path inside that file is expected.

---

## Development (this package)

```bash
composer install
composer validate --no-check-publish
./bin/ai-index list --raw
./bin/ai-index setup --dry-run
./bin/ai-index generate --dry-run
./bin/ai-index wiring:export --dry-run
```
