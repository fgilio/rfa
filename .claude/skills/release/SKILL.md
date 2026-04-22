---
name: release
description: "Tag, build, and publish a new rfa release via GitHub Actions. Use when: the user says /release, asks to ship/publish/release a version, or wants to tag and push."
user_invocable: true
---

# Release

Ship a new version of rfa through the GitHub Actions release pipeline.

## Release gating

Two GitHub rulesets guard the pipeline server-side:

- **"Protect main"** (branch ruleset on `main`): requires PR + all 5 CI checks green (`lint`, `types`, `test-core`, `test-browser`, `benchmark-perf`). Admin bypass enabled for emergency direct pushes.
- **"Protect release tags"** (tag ruleset on `v*`): same 5 checks must be green on the tagged SHA. `git push --tags` is rejected by GitHub if CI isn't green on that SHA. Admin bypass enabled so mistaken tags can be deleted (`git push --delete origin vX.Y.Z`).

If pre-flight fails locally, the tag push will also fail at the server. Don't bypass.

## Steps

### 1. Determine version

Ask the user what version to release. Suggest the next version based on the latest tag:

```bash
git tag --sort=-v:refname | head -5
```

Version must be semver with `v` prefix (e.g. `v0.2.0`). Validate the tag doesn't already exist.

Use standard semver to guide the suggestion:
- **Major** (vX.0.0): breaking changes
- **Minor** (v0.X.0): new features, capabilities
- **Patch** (v0.0.X): bug fixes, tweaks, UI polish, performance

### 2. Pre-flight checks

Run pre-flight checks:

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

### 7. Write release notes

Generate release notes from the commits since the previous tag:

```bash
git log <prev-tag>..vX.Y.Z --oneline --no-merges
```

Write concise, user-facing notes following this format:

```markdown
**vX.Y.Z is a {small | focused | feature | bugfix | polish} release focused on {1-3 user-visible themes}.** {Optional second sentence naming the standout change in plain user-facing terms.}

## What's new
- **Feature name** — One-line description

## Improvements
- Description of improvement

## Fixes
- Description of fix
```

Opener rules:
- Open with one bold sentence framing the release: `**vX.Y.Z is a {kind} release focused on {themes}.**` Optionally add 1 follow-up sentence for the standout item. Hard cap: 60 words total.
- Themes are user outcomes, not subsystems. Good: "comment editing", "session recovery", "diff readability". Avoid: "refactor of LoadFileDiffAction", "Livewire SPA navigation". Internal terms belong in the bullets, not the opener.
- Pick a "kind" that matches scope: `small`, `focused`, `feature`, `bugfix`, `polish`, `maintenance`. Skip "big" / "high-impact" hype.
- No em dash in the opener. Use parentheses, semicolons, or split into two sentences.
- Skip the opener entirely for trivial single-fix patch releases (the bullet list is enough).

Body rules:
- Group into **What's new**, **Improvements**, **Fixes** (omit empty sections)
- Bold the feature/fix name, then a brief description
- Skip internal commits (CI, refactoring, tests, deps) unless user-visible
- One line per item, no fluff
- For patch releases with 1-2 changes, keep it very short

Example:

```markdown
**v0.4.0 is a focused release on session recovery and review-page polish.** Reopening rfa now restores your in-progress comments even after an unexpected quit.

## What's new
- **Session recovery** — In-progress comments and reviewed-file state survive crashes and force-quits.

## Improvements
- **Empty-state copy** — Clearer message when a repo has no diffs to review.
- **Single-repo picker** — Newly-added repos open automatically after picking.

## Fixes
- **Git timeouts** — `GetCurrentHeadAction` no longer hangs on slow repos.
```

Update the draft release with the notes:

```bash
gh release edit vX.Y.Z -R fgilio/rfa --notes "..."
```

### 8. Publish

Publish the release immediately (do not leave as draft):

```bash
gh release edit vX.Y.Z -R fgilio/rfa --draft=false
```

Print the release URL when done.
