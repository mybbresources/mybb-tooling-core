# Building and verifying releases

The client trust maps pin Ed25519 key `catalog-2026`, with decoded public-key SHA-256 fingerprint `165e49013244da21ad4312e49972d4a253f40f0bdb05dc9e23b93727f7c73d9a`. Public keys authorize package signatures; they do not authenticate a source checkout or PHAR download. Never establish trust from a key bundled beside an unverified download.

The signed toolchain alone owns `inc/plugins/mybb-tooling/config/trusted-keys.json`. Admin CP reads that central ring. Routine rotation ships an overlap toolchain update signed by an already trusted key; compromised-key recovery requires an independently authenticated update. Private signing keys never belong in source, command arguments, logs, or artifacts.

`tools/build-bootstrap.php` builds signed toolchain/repository bundles. `tools/build-phars.php` verifies their signatures, digests, identities, and exact archive maps before embedding them into the installer. Both PHARs carry the same independent public trust map. Build inputs and the private key are supplied through protected files or the release environment.

The tagged release workflow calls the complete public CI matrix, requires its configured key ID and protected signing secret, builds and smoke-tests the artifacts, generates GitHub provenance attestations, and publishes GitHub Release assets. Secrets must be provisioned separately; importing this source does not publish a release. No official binary release is available until that workflow has completed and its output has been verified.

For a published release, verify each PHAR with `gh attestation verify FILE --repo mybbresources/mybb-tooling-core`, then compare its SHA-256 sidecar. A sidecar alone detects transfer corruption but does not authenticate the publisher. Package manifests separately require their pinned Ed25519 signature and digest checks. Preserve published package versions/bytes; corrected contents require a new version.

Internal website deployment and operational runbooks are intentionally outside this public repository.
