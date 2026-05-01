# Actions

Business logic. Each Action is a single use-case callable from any interface (Livewire, CLI, API). See root `CLAUDE.md` → Architecture for the full layer story.

## Caching

- `LoadFileDiffAction` uses self-healing cache: validates cached entries have expected keys (e.g. `syntaxStyles`) before returning. Stale entries are re-computed and overwritten automatically. Prefer adding key checks over bumping `DiffCacheKey` version for format changes.
- Actions may accept an optional `cacheKey` param for opt-in caching. Use `DiffCacheKey::for()` for diff cache keys.
