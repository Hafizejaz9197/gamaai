# Gama AI

AI-assisted editing for Avada WordPress websites.

| Path | What it is |
| --- | --- |
| `plugin/gama-ai/` | WordPress plugin (PHP). Reads Avada pages, drafts, publishes and rolls back changes. |
| `saas/` | Laravel backend: accounts, sites, plugin tokens and the usage ledger. See `saas/README.md`. |
| `docs/` | Architecture and verification notes, starting with `phase0-avada-findings.md`. `edit-operations.md` is the contract for AI edits. |


## Plugin development

```sh
cd plugin/gama-ai
composer install
vendor/bin/phpunit       # unit tests
bin/build-zip.sh         # installable zip in build/
```

Pushing a tag named `plugin-v<version>` runs the tests and publishes the zip as a GitHub release.
