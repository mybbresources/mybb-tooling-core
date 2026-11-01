# Synthetic site fixtures

These filesystem-only fixtures contain no real database, users, credentials, or third-party plugin imports. Generate one with `php sample-sites/create-fixture.php demo --scenario=clean`; add `--reset` only to replace that named generated fixture.

Scenarios: `clean`, `empty`, `partial`, `unrelated`, `modified-owned`, `symlink-parent`, and `interrupted`. The generated metadata records the expected version/state and reset command. Runtime data is ignored by Git. Full live database and catalog-application integration is maintained separately from this public source tree.
