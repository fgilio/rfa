## Releasing

Tag triggers the release workflow (`workflows/release.yml`):

```
git tag v1.2.0
git push --tags
```

- Server-side ruleset "Protect release tags" rejects `v*` tag pushes on any SHA without green CI (all 5 checks: lint, types, test-core, test-browser, benchmark-perf). Admin bypass enabled for yanking bad tags. Because the ruleset is the CI gate, `release.yml` only runs the macOS build.
- Build runs on `macos-15` (arm64), publishes to GitHub Releases as **draft**
- Publish the draft via `gh release edit vX.Y.Z --draft=false`
- Tag must be `vX.Y.Z` (semver with `v` prefix). Version injected from tag automatically.

## Updater

- Provider: GitHub Releases (`config/nativephp.php` defaults to `github`)
- `repo`/`owner` defaults hardcoded in config because `cleanup_env_keys` strips `GITHUB_*` from bundled `.env`
- electron-updater reads `latest-mac.yml` from the latest published (non-draft) release
- Auto-update uses the `.zip` artifact; `.dmg` is for manual download

## Code signing (not yet configured)

Add these secrets to enable signing and notarization:

- `CSC_LINK` - base64-encoded Developer ID `.p12` certificate
- `CSC_KEY_PASSWORD` - certificate password
- `NATIVEPHP_APPLE_ID` - Apple ID email
- `NATIVEPHP_APPLE_ID_PASS` - app-specific password
- `NATIVEPHP_APPLE_TEAM_ID` - Apple Developer Team ID

`notarize.js` already handles notarization when these are present. Pass them as `env:` in the build step.
