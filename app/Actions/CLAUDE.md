# Actions

Business logic. Each Action is a single use-case callable from any interface (Livewire, CLI, API). See root `CLAUDE.md` → Architecture for the full layer story.

## Caching

- `LoadFileDiffAction` stores a `LoadedDiff` envelope stamped with `LoadedDiff::VERSION`. Entries written under any other version read back as misses and recompute, so a payload shape change means bumping that constant, not `DiffCacheKey`'s prefix. Bump the prefix only when cache *identity* changes (a new input to the key).
- Every load ends in one `DiffLoadOutcome`. Only `DiffLoadOutcome::TransientError` is uncacheable, so a failed git process retries on the next read instead of serving the failure for the whole TTL.
- Actions may accept an optional `cacheKey` param for opt-in caching. Use `DiffCacheKey::for()` for diff cache keys.
