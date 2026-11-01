# Shared Contracts

**Status:** Implemented across shared PHP tooling, CLI, Admin CP repository, and catalog APIs; a live MyBB 1.8.41 install/bootstrap/plugin lifecycle run has passed locally.
**Scope:** Normative shared behavior for installer, CLI, repository plugin, and catalog.

## 1. Entities and invariants

- **Site:** one MyBB installation rooted at a canonical filesystem path and identified locally by its validated MyBB config/install markers. A URL does not identify a local installation.
- **Plugin:** catalog identity (`slug`) with immutable, ordered releases.
- **Release:** immutable metadata plus one package archive, digest, signature, compatibility declarations, provenance, license, and explicit file manifest.
- **Installed record:** local record of plugin slug/version, package digest, each destination path and installed digest, install time, and activation state.
- **Package path:** relative normalized POSIX path inside the archive or site root. Absolute paths, traversal, symlinks/hardlinks, device files, and ambiguous Unicode/path normalization are rejected.

## 2. Manifest v1

The draft in `../PLUGIN-MANIFEST.md` is refined as follows. JSON is UTF-8; unknown required schema versions fail closed. Required release fields: `schema_version`, `slug`, `name`, `version`, `description`, `authors`, `license`, `source`, `requirements`, `package`, and nonempty `files`. `package` has immutable URL, SHA-256, byte size, and signature/key ID. `source` distinguishes `original` from `upstream`; upstream records canonical source URL, upstream identifier/version, attribution, and rights review status. `requirements.mybb` and `requirements.php` use the shared comparator grammar: whitespace-separated AND constraints using `>=`, `<=`, `>`, `<`, `=`, `^`, or `~`, with one to three numeric version components; bare prefix wildcards such as `1.8.*` are supported. Wildcards cannot be combined with operators other than `=`. `files[]` has `source`, `destination`, and a controlled role enum (`plugin`, `language`, `asset`, `admin`, `other`). Destination must be unique within a release. No scripts, arbitrary commands, or post-install hooks are permitted in v1.

`uninstall.remove_owned_files` is always interpreted through the installed-file ledger, never as permission to delete by glob. `preserve_user_data` defaults true and is mandatory in v1. Any DB/data cleanup requires a separate future spec and explicit user action.

## 3. Package and trust requirements

- **SH-001** Catalog release metadata and package bytes are immutable after publish. Corrections create a new release or a clearly revoked release record.
- **SH-002** Clients require HTTPS, validate TLS, verify metadata signature and package SHA-256 before extraction, and reject expired/revoked signing keys/releases.
- **SH-003** Extraction occurs into a private staging directory. Every archive entry must match a declared manifest source exactly; undeclared files cause rejection. Entry count and expanded size are bounded.
- **SH-004** Destination paths remain within the site root after canonical resolution. Existing symlinked parent paths that escape the root are rejected.
- **SH-005** Before replacement, clients record backup bytes and digest. If application fails, restore the previous file set and ledger. If rollback is incomplete, report exact paths and preserve recovery data.
- **SH-006** If destination content differs from the ledger's installed digest, update/uninstall refuses to overwrite/delete it by default. An explicit force option may back it up and proceed, with the affected path list shown first.
- **SH-007** A package manager never enables/deactivates a plugin implicitly as a side effect of file copy. Activation is a separate user action.
- **SH-008** Checksums protect integrity; signature trust establishes catalog authorization. Both checks are required.
- **SH-009** Before removing a package that owns plugin-role files, each client must establish that those plugins are inactive using MyBB's authoritative activation cache. A positively active plugin or unavailable activation state blocks removal; deactivation is a separate explicit action. The local ownership ledger's active flag is an additional refusal signal, not a substitute for checking MyBB.

The installed trust ring lives at `inc/plugins/mybb-tooling/config/trusted-keys.json` and is owned only by the `mybb-tooling-core` package. Plugin packages must not carry or overwrite that path. For routine key rotation, publish a core release signed by a key already trusted by the site and include both the outgoing and incoming public keys; after that ring is installed, subsequent packages may be signed by the incoming key. Emergency recovery from a compromised signer still requires an out-of-band trusted pin/update and is a release-operations responsibility.

## 4. API conventions

- **SH-010** API base `/api/v1`; JSON envelope includes `data` or stable `error` (`code`, safe `message`, `retryable`, `request_id`).
- **SH-011** List endpoints paginate with opaque `next_cursor`, bounded page size, stable ordering, and filters documented by endpoint.
- **SH-012** Public discovery exposes published, non-revoked release metadata only. Private draft/moderation details are never exposed by public API.
- **SH-013** Mutations in the versioned JSON API require authentication and idempotency keys. Reusing a key with a different request body returns conflict. The sole v1 exception is public plugin report intake: it accepts anonymous reports without storing reporter identity, but still requires a payload-bound idempotency key and rate limits submissions. Any future administrative JSON mutation must require an authenticated maintainer/admin and an idempotency key. Admin CP/maintainer HTML forms use their authenticated session and CSRF protections; SH-013's header requirement applies to JSON API mutations.
- **SH-014** Compatibility mismatches return structured requirements and detected values; clients never treat a warning as compatible unless the user explicitly overrides a declared soft constraint.
- **SH-015** Clients distinguish offline/transient errors from authorization, not-found, validation, incompatibility, and revoked-release errors.

## 5. Acceptance criteria

1. A crafted archive containing `../`, absolute paths, symlinks, undeclared entries, duplicate normalized paths, or oversized expanded content is rejected before writes.
2. A modified installed file is preserved and surfaced during update/uninstall.
3. A corrupt digest, invalid signature, unknown required manifest schema, or revoked signing key prevents install.
4. Retry of an idempotent API mutation does not duplicate releases or state transitions.
5. Catalogs can list metadata without disclosing drafts, credentials, or moderation notes.
6. Anonymous report intake is the only public JSON mutation, rejects missing/conflicting idempotency keys, is rate-limited, and stores no reporter identity.
7. Removing a managed plugin package while any owned plugin is active or activation state cannot be verified is refused without changing files or the ownership ledger.

## 6. Security, privacy, operations

Never log secrets, credentials, raw private keys, database passwords, or package contents. Audit publish/revoke/key rotation and destructive file actions. Catalog downloads are rate-limited and served with immutable cache headers. Retain install ledgers and rollback artifacts only as long as needed by the site's stated backup policy. All errors must be actionable and accessible in CLI/Admin CP.

## Separate core dependency (manifest schema v2)

Ordinary plugins may continue to use schema v1 without package dependencies. A package declaring dependencies must use schema v2 and provide `requirements.packages`, an object mapping package slugs to the shared version comparator ranges. Schema v1 rejects that field; older clients reject schema v2. Dependencies must already be installed, compatible, and unchanged. Cycles, incompatible updates of a required package, and removal of a package with installed dependents are rejected before file changes, including forced operations.

The reserved packages use schema v2. `mybb-tooling-core` owns only `inc/plugins/mybb-tooling/` and the MyBB descriptor `inc/plugins/mybb_tooling_core.php`; it has no package dependencies. `mybb-cli` owns only `inc/plugins/data/mybb-tooling/bin/mybb`. `mybbresources-repository` owns its plugin and Admin CP module. Both clients declare `mybb-tooling-core >=0.1.0 <1.0.0` and check installed core ownership, version, and file integrity. Core availability depends on installation, not an activation flag: the library has no activation-time runtime hooks. Removing core requires removal of dependent clients first.

Standalone Composer projects resolve the public core library through VCS with a version constraint and lockfile. Tracked copies under `packages/` are removed. Local coordinated CI can use a temporary path repository directly referencing canonical source. Portable installer and CLI PHARs remain self-contained so they can bootstrap or recover a site before core exists.

Existing `mybb-toolchain` installations require explicit `mybb plugin install core.json core.zip --migrate-toolchain --path=SITE`, reviewed then confirmed with `--yes`. Use a new standalone CLI or PHAR for this transition. Migration refuses force, mixed ownership, missing files, or modified legacy files. It transfers the CLI ownership record, replaces the core files, and records provenance while preserving the CLI executable until its separate update. Existing repository ownership gains the core requirement. Failure restores the original combined ledger and files.

An interrupted transition leaves a private `transactions/TRANSACTION/core-migration.json` journal. Use the standalone CLI/PHAR: `mybb core recover-migration --path=SITE --journal=PATH --dry-run`, then `--yes`. Recovery preflights the entire recorded file set, refuses changed ledgers and symlinks, preserves operator edits, and refuses committed migrations. Recovery cannot replay arbitrary package scripts. Keep the journal and backups until the transition is verified.
