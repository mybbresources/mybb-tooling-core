# Manifest v1 example

The manifest is published as `meta.json` and signed as canonical JSON with the Ed25519 signature field omitted. `files[].source` names an exact archive entry; `files[].destination` names its site-relative MyBB destination. Every non-directory archive entry must appear once in the list. Package values below are placeholders and this example is not an installable release.

```json
{
  "schema_version": 1,
  "slug": "example-plugin",
  "name": "Example Plugin",
  "version": "1.2.0",
  "description": "A short description of the plugin.",
  "authors": ["Example Author"],
  "license": "MIT",
  "source": { "type": "original" },
  "requirements": {
    "mybb": ">=1.8.41 <1.9.0",
    "php": ">=8.2"
  },
  "package": {
    "url": "https://plugins.mybbresources.com/api/v1/plugins/example-plugin/releases/1.2.0/package",
    "sha256": "REPLACE_WITH_64_HEX_DIGEST",
    "size": 12345,
    "signature": "BASE64_ED25519_SIGNATURE",
    "key_id": "catalog-2026"
  },
  "files": [
    {
      "source": "upload/inc/plugins/example.php",
      "destination": "inc/plugins/example.php",
      "role": "plugin"
    },
    {
      "source": "upload/inc/languages/english/example.lang.php",
      "destination": "inc/languages/english/example.lang.php",
      "role": "language"
    }
  ],
  "uninstall": {
    "remove_owned_files": true,
    "preserve_user_data": true
  }
}
```

For an upstream source, `source` must also include its canonical HTTPS `url`, `upstream_id`, `upstream_version`, `attribution`, HTTPS `rights_evidence_url`, and `rights_status: "verified"`. A catalog record without verified redistribution rights may be published only as a metadata/link listing and must not contain a package. Unknown schema versions, unsafe paths, undeclared archive files, hooks, scripts, and migrations are rejected in v1.

`requirements.mybb` and `requirements.php` use the shared comparator grammar: whitespace-separated AND constraints using `>=`, `<=`, `>`, `<`, `=`, `^`, or `~`, with one to three numeric version components. Bare prefix wildcards such as `1.8.*` and `8.*` match numeric version components; wildcard constraints cannot be combined with comparison operators other than `=`. Invalid range syntax is rejected before signing/publishing or installing.
