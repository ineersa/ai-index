# Progress

## Completed (current state)

Implemented **Phase 1 + 2 + 3 + 4** for standalone `ai-index` package.

---

## Phase 1 — package + setup recipe

- CLI entrypoint: `bin/ai-index`
- Config foundation:
  - `src/Config/IndexConfig.php`
  - `src/Config/ConfigLoader.php`
- Setup installer:
  - `src/Template/AgentsSetupInstaller.php`
  - `src/Command/SetupCommand.php`
- Templates:
  - `resources/templates/.agents/skills/ai-index/SKILL.md`
  - `resources/templates/.agents/index-maintainer.md`
  - `resources/templates/AGENTS.section.md`

Implemented `ai-index setup [--project-root=...] [--dry-run] [--force]` with idempotent marker-based AGENTS upsert.

---

## Phase 2 — discovery + callgraph preflight

- Target resolution:
  - `src/Discovery/PhpTargetResolver.php`
- Callgraph modules:
  - `src/CallGraph/CallGraphGenerator.php`
  - `src/CallGraph/CallGraphLoader.php`

Supports `--all`, `--changed`, explicit targets, and graceful callgraph fallback.

---

## Phase 3 — generate pipeline

- Class index extraction/writing:
  - `src/Index/ClassIndexBuilder.php`
  - `src/Index/FileIndexWriter.php`
  - `src/Index/DiWiringMapLoader.php`
- Namespace regeneration:
  - `src/Index/NamespaceIndexRegenerator.php`
- End-to-end orchestration:
  - `src/Index/IndexGenerationPipeline.php`
  - `src/Command/GenerateCommand.php`

`ai-index generate` now writes:
- `src/**/docs/*.toon`
- `src/**/ai-index.toon`

with skip/force/dry-run behavior and stats output.

---

## Phase 4 — Symfony DI wiring export

Implemented full `wiring:export` pipeline:

- `src/Wiring/SymfonyContainerBuilderFactory.php`
- `src/Wiring/WiringReferenceExtractor.php`
- `src/Wiring/WiringMapExporter.php`
- `src/Command/ExportWiringCommand.php`

Capabilities:
- Boot Symfony kernel from project config
- Compile container and extract:
  - serviceDefinitions
  - aliases
  - injectedInto edges
- Emit Toon payload (`agent-core.di-wiring/v1` by default)
- Works with `--dry-run`

---

## Phase 5 — parity fix (auto wiring on generate)

Implemented original behavior parity:
- `generate` now auto-runs DI wiring export before class/namespace generation.
- Added `--skip-wiring` option for explicit opt-out.
- Generate pipeline now accepts in-memory wiring map override from exporter, so dry-runs include wiring context without requiring a persisted wiring file.
- `WiringMapExporter` now returns `wiringByClass` payload for direct pipeline injection.

Result:
- running `vendor/bin/ai-index generate ...` now produces wiring-enriched class `.toon` files by default (same intended flow as legacy orchestration).

---

## Documentation

- Added package README with installation, configuration, command usage, and troubleshooting:
  - `README.md`

---

## Consumer integration smoke test

Validated against:
- `/home/ineersa/projects/symfony-web-template`

Actions:
- Added local path repository to consumer composer config
- Required package as dev dependency
- Ran:
  - `composer exec ai-index -- wiring:export --dry-run`
  - `composer exec ai-index -- wiring:export`
  - `composer exec ai-index -- generate --all --force --skip-namespace`

Result:
- wiring map generated at `var/reports/di-wiring.toon`
- class docs indexes generated for consumer classes

---

## Validation performed (package)

- `php -l` on all PHP files ✅
- `composer validate --no-check-publish` ✅
- `composer dump-autoload` ✅
- `./bin/ai-index list --raw` ✅
- `./bin/ai-index setup --dry-run` + idempotency ✅
- `./bin/ai-index generate --dry-run --skip-wiring` ✅
- `./bin/ai-index wiring:export --dry-run` / normal run (in consumer) ✅
- consumer smoke (`symfony-web-template`):
  - `composer exec ai-index -- generate --dry-run --all --force --skip-namespace` ✅
  - `composer exec ai-index -- generate --all --force --skip-namespace` ✅
  - verified `src/docs/Kernel.toon` contains `wiring` block ✅

---

## Remaining work (before stable release)

1. Add unit tests for resolver/callgraph/class-builder/namespace/wiring modules.
2. Add integration fixture app tests for generate + wiring export.
3. Integrate package into `agent-core` Castor tasks and remove legacy scripts after parity checks.
