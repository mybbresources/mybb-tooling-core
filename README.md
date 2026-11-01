# MyBB tooling core

MIT-licensed shared PHP tooling and reproducible CLI packaging for MyBB. This repository contains reusable code, client source snapshots, synthetic examples, and public safety checks. Website applications, credentials, infrastructure configuration, and operational history are kept separately.

- `src/`: manifests, Ed25519 trust, catalog HTTP, compatibility, transactional file ownership, rollback/recovery, and MyBB database/lifecycle helpers.
- `mybb-cli/`, `mybb-installer-cli/`, `mybb-plugin-repository/`: coordinated snapshots of the public components.
- `tools/`: signing-key generation and reproducible signed-bundle/PHAR builders.
- `examples/synthetic/`: original MIT sample plugins with explicit `meta.json` file maps.
- `docs/SHARED-CONTRACTS.md`: normative package/API/safety contract.

PHP 8.2–8.4 is supported on Ubuntu 24.04 x86_64 and macOS 15 Intel/Apple Silicon. Fresh MyBB installs and plugin operations target 1.8.41–1.8.x; the installer also accepts 1.8.40 as an upgrade source only. Windows is outside v1.

Run `php tests/run.php`, `php tests/phar-smoke.php`, and `php tests/phar-bootstrap-smoke.php` for the public checks. `php tools/install-component-dependencies.php` installs local Composer dependencies directly from canonical source without copying tracked library snapshots. Generated keys, site data, and release artifacts must stay outside tracked source.

See each component README for commands and [RELEASING.md](RELEASING.md) for artifact verification. PHAR releases must include verified signed companion bundles; a fresh install skips those only with explicit `--core-only`. Package copying never implicitly activates plugins, and uninstall preserves user data and locally changed files by default.
