---
name: release
description: "Tag, build, and publish a new rfa release via GitHub Actions. Use when: the user says /release, asks to ship/publish/release a version, or wants to tag and push."
user_invocable: true
---

# Release

Ship a new version of rfa through the GitHub Actions release pipeline.

## Steps

### 1. Determine version

Ask the user what version to release. Suggest the next patch/minor/major based on the latest tag:

```bash
git tag --sort=-v:refname | head -5
```

Version must be semver with `v` prefix (e.g. `v0.2.0`). Validate the tag doesn't already exist.

### 2. Pre-flight checks

Run from `src/`:

```bash
composer test:lint
composer test:types
php -d memory_limit=512M vendor/bin/pest --testsuite=Core
```

All must pass before proceeding. Do NOT skip or ignore failures.

### 3. Commit pending changes

If there are uncommitted changes, ask the user whether to commit them in this release or stash them. Do not commit automatically without asking.

### 4. Tag and push

```bash
git tag vX.Y.Z
git push origin main --tags
```

### 5. Wait for CI

Monitor the release workflow:

```bash
gh run list --workflow=release.yml --limit 1 -R fgilio/rfa
```

Then watch until completion:

```bash
gh run watch <run-id> -R fgilio/rfa --exit-status
```

If the build fails, show the failure summary and stop. Do not retry automatically.

### 6. Verify draft release

```bash
gh release view vX.Y.Z -R fgilio/rfa
```

Confirm the draft has all expected artifacts:
- `rfa-X.Y.Z-arm64.dmg`
- `rfa-X.Y.Z-arm64.zip`
- `latest-mac.yml`
- blockmap files

### 7. Publish

Ask the user for confirmation before publishing:

```bash
gh release edit vX.Y.Z -R fgilio/rfa --draft=false
```

Print the release URL when done.
