# Plan: server-side fallback for "parent of root commit" in range URLs

## Context

When the user multi-selects commits in the branch-explorer and the oldest selected commit is the repo's **root commit**, RFA shows a generic Git error:

> Git error — fatal: bad revision 'a50dc07…^'

Cause: `public/js/branch-explorer.js:190` builds the base ref as `oldest.hash + '^'`. Root commits have no parent, so `<root>^` is not a valid git revision. The user's intent is unambiguous (they want a diff that covers all selected commits, including the very first one), and every surveyed review tool — GitHub, GitLab, GitLens, JetBrains, Sourcetree, Tower, Fork — silently falls back to diffing against git's empty-tree object (`4b825dc642cb6eb9a060e54bf8d69288fbee4904`). `DiffTarget::commit()` already does this for the **single-commit-against-root** case; this plan extends the same behavior to the **range** path.

We fix it server-side. The JS stays naive (keeps emitting `<hash>^`). A new Action on the PHP side normalizes the base ref so external deep-links (URLs shared out-of-band) get the same treatment.

## Approach

New `ResolveRangeAction` that encapsulates base-ref resolution, including the root-commit fallback. Called from `mount()` in the review page, replacing the inline string-concat at line 156.

The action only pays for an extra git lookup when the base ref is `^`-suffixed (the exclusive case that can hit this bug). Named refs and plain SHAs are passed through unchanged.

## Files to change

### New: `app/Actions/ResolveRangeAction.php`

Parallels `ResolveCommitAction` (`app/Actions/ResolveCommitAction.php:10`). Constructor-injects `GitMetadataService`.

```php
public function handle(string $repoPath, string $headRef, ?string $baseRef): DiffTarget
{
    $effectiveBase = $baseRef ?? $headRef.'^';

    if (str_ends_with($effectiveBase, '^')) {
        $inner = substr($effectiveBase, 0, -1);
        $innerHash = $this->gitMetadataService->resolveRef($repoPath, $inner);

        if ($innerHash !== null
            && $this->gitMetadataService->getCommitParents($repoPath, $innerHash) === []) {
            $effectiveBase = DiffTarget::EMPTY_TREE_HASH;
        }
    }

    return DiffTarget::fromRefs($effectiveBase, $headRef);
}
```

Reuses existing helpers:
- `GitMetadataService::resolveRef()` (`app/Services/GitMetadataService.php:139`) — returns full SHA or null.
- `GitMetadataService::getCommitParents()` (`app/Services/GitMetadataService.php:157`) — returns `[]` for root commits.
- `DiffTarget::EMPTY_TREE_HASH` and `DiffTarget::fromRefs()` (`app/DTOs/DiffTarget.php:11,34`).

### Edit: `resources/views/pages/⚡review-page.blade.php`

In `mount()`, the `elseif ($ref !== null)` branch at lines 153–157 currently does:

```php
} elseif ($ref !== null) {
    $this->diffTo = $ref;
    $this->diffFrom = $baseRef ?? $ref.'^';
}
```

Replace with:

```php
} elseif ($ref !== null) {
    $target = app(ResolveRangeAction::class)->handle($this->repoPath, $ref, $baseRef);
    $this->diffFrom = $target->from();
    $this->diffTo = $target->to();
    $this->loadCommitInfo();
}
```

Also apply the same resolution to the explicit `/r/{from}..{to}` branch (lines 148–152) for symmetry, so a hand-crafted URL like `/r/<rootHash>^..HEAD` doesn't hit the same error:

```php
} elseif ($from !== null && $to !== null) {
    $target = app(ResolveRangeAction::class)->handle($this->repoPath, $to, $from);
    $this->diffFrom = $target->from();
    $this->diffTo = $target->to();
    $this->loadCommitInfo();
}
```

No change to `ResolveCommitAction` (single-commit path already handles root).

### New: `tests/Unit/Actions/ResolveRangeActionTest.php`

Pest unit test using `InteractsWithTestRepositories` to init a real repo with a root + one child commit. Cover:

1. `baseRef = <nonRoot>^` → resolves normally, returns `DiffTarget` with parent hash as `from`.
2. `baseRef = <root>^` → falls back to `DiffTarget::EMPTY_TREE_HASH` as `from`.
3. `baseRef = null` → derives `<headRef>^` and applies the same logic.
4. `baseRef = <plainSha>` (no `^`) → passed through unchanged, no git lookups.
5. `baseRef = <invalidRef>^` where inner ref doesn't resolve → passed through; existing git-error path downstream keeps owning the "truly bad ref" case (we don't hide unrelated errors).

### Extend: `tests/Unit/Livewire/ReviewPageRangeAndSelectionTest.php`

Add a test asserting that mounting `/p/{slug}/{rootHash}/{rootHash}^` sets `diffFrom` to `DiffTarget::EMPTY_TREE_HASH`. Mock `ResolveRangeAction` similarly to how the file already mocks `ResolveCommitAction` (lines 86–92).

## What we are explicitly **not** changing

- `public/js/branch-explorer.js` stays as-is. URLs in the wild keep the `<hash>^` form, which is semantically honest ("parent of this commit"); server handles the degenerate case.
- No new UI affordance. Matches dominant industry UX (silent fallback). No "include root commit" toggle.
- Generic git-error path is preserved for any other bad revisions — we only special-case the `<root>^` pattern.

## Verification

1. **Unit tests**: `php artisan test --compact --filter=ResolveRangeAction` and `--filter=ReviewPageRangeAndSelection`.
2. **Full suite**: `composer test`.
3. **Types + lint**: `composer test:types` and `composer test:lint`.
4. **Manual smoke test** (desktop app):
   - `composer native:dev`
   - Open a repo with few enough commits that selecting all 4 crosses the root (e.g. `/Users/fgilio/pla/secureframe`, the repo from the bug report).
   - ⌘B → select all commits → Apply. Expect the full diff, not a Git error screen.
   - Verify single-commit navigation still works (regression check on `ResolveCommitAction` path).
   - Verify a normal range that doesn't touch the root still works.
5. **Deep-link check**: paste `/p/{slug}/{rootHash}/{rootHash}%5E` into the app URL bar (or use `Livewire.navigate` in console). Should render the full root-commit content, not an error.

## Open question for the user

None that block implementation. Noted for post-merge judgment: if users regularly share review URLs externally and the synthetic `^` form shows up in shared URLs, Option 2 (canonicalizing URLs client-side to emit the empty-tree hash directly) becomes more attractive. Revisit only if we see that happening.
