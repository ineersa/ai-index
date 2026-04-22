## AI Documentation Index

This repository uses AI index files for fast code navigation.

- Class docs: `src/**/docs/*.toon`
- Namespace indexes: `src/**/ai-index.toon`

Generated index files are managed via `vendor/bin/ai-index`.

### IDE indexing rule

- JetBrains IDEs must **not** index `*.toon` files (exclude them from indexing).

### Recommended commands

- `vendor/bin/ai-index setup`
- `vendor/bin/ai-index wiring:export`
- `vendor/bin/ai-index generate --changed`
- `vendor/bin/ai-index generate --all --force`

For curated description updates, use `.agents/index-maintainer.md`.
