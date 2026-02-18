# Code Highlighting for rfa - Research Report

## Context

rfa currently renders diffs as plain text in a browser-based HTML viewer. The backlog lists "Code highlighting" as a deferred feature. This report evaluates approaches to add syntax highlighting to the diff viewer.

### rfa constraints
- PHP 8.3 / Laravel 12 / Livewire 4 / Alpine.js / Tailwind CDN
- **No build step** - just Composer + CDN
- Diffs are **lazy-loaded** via Livewire intersection observer
- Individual diff lines rendered in Blade templates (`diff-file.blade.php`)
- Must preserve diff styling (added/removed/context line backgrounds)
- Large diffs possible (up to 512KB configurable)

---

## Server-Side PHP Options

### 1. Phiki (`phikiphp/phiki`)

**What**: Pure PHP syntax highlighter using VS Code's TextMate grammars.

| Aspect | Details |
|--------|---------|
| Languages | 200+ (TextMate grammar library) |
| Themes | All VS Code themes |
| Quality | VS Code-level accuracy (best in class for PHP) |
| Output | HTML, terminal ANSI, Markdown |
| Dependencies | Zero - pure PHP |
| Maintenance | Active, v2.1.0 (Jan 2026) |
| Integration | CommonMark extension available |

**Pros**:
- Highest highlighting accuracy among PHP options
- Zero dependencies, Composer-only install
- VS Code themes = familiar, high-quality color schemes
- Supports light/dark dual themes natively

**Cons**:
- ~100ms per highlight operation (estimated) - could add up for large diffs
- PCRE2 engine differences from Oniguruma affect ~12 of 200+ grammars
- Newer library, smaller community than alternatives

**Fit for rfa**: Excellent. Pure PHP, no build step, best accuracy. Main concern is per-line performance on large diffs (mitigable with caching).

---

### 2. Tempest Highlight (`tempest/highlight`)

**What**: Fast, extensible PHP syntax highlighter using pattern-based tokenization.

| Aspect | Details |
|--------|---------|
| Languages | PHP, HTML, CSS, Blade + extensible |
| Themes | Built-in CSS themes + InlineTheme for inline styles |
| Quality | Good but less accurate than TextMate-based solutions |
| Output | HTML with CSS classes, terminal ANSI |
| Dependencies | Minimal |
| Maintenance | Active, v2.17.1 (Feb 2026), 61 releases |

**Pros**:
- Very fast (designed as response to slow alternatives)
- InlineTheme class - can inject styles without external CSS files
- Built-in diff markup: `{+ added +}` / `{- removed -}` syntax
- Blade support out of the box
- Simple API: `$highlighter->parse($code, 'php')`

**Cons**:
- Limited language support compared to TextMate-based options
- Pattern-based approach = less accurate than grammar-based
- Creator acknowledges incomplete language coverage
- Adding new languages requires writing custom pattern classes

**Fit for rfa**: Good for PHP/Laravel-heavy repos. Fast. But limited language coverage is a real issue for a general-purpose code review tool.

---

### 3. Highlight.php (`scrivo/highlight.php`)

**What**: PHP port of highlight.js. Mature, stable.

| Aspect | Details |
|--------|---------|
| Languages | 185 |
| Themes | highlight.js theme stylesheets |
| Quality | Moderate (regex-based, like highlight.js) |
| Output | HTML with CSS classes |
| Dependencies | Minimal |
| Maintenance | Stable but slow cadence, latest v9.18.1.10 (Dec 2022) |

**Pros**:
- Broad language support (185 languages)
- Mature, battle-tested (PHP port of popular JS library)
- Familiar highlight.js themes
- Auto-detection available

**Cons**:
- Auto-detection is slow ("brute force")
- Last release Dec 2022 - aging
- Moderate accuracy
- v10 under development but no release date

**Fit for rfa**: Decent breadth but aging. Not recommended over Phiki.

---

### 4. Torchlight (API-based)

**What**: Cloud API using VS Code engine. Commercial product.

| Aspect | Details |
|--------|---------|
| Languages | All VS Code languages |
| Themes | All VS Code themes |
| Quality | VS Code-level |
| Output | Pre-rendered HTML |
| Diff support | Yes - built-in diff annotation system |

**Pros**:
- Best diff support of any option (annotations for add/remove/focus)
- Highest quality highlighting
- Laravel integration package available

**Cons**:
- **Requires internet** - bad for local dev tool
- Commercial (free for personal/OSS with attribution)
- External dependency for a local-first tool
- Latency per API call

**Fit for rfa**: Poor. Internet dependency contradicts rfa's local-first nature.

---

## Client-Side JavaScript Options

### 5. Prism.js

| Aspect | Details |
|--------|---------|
| Bundle | 11.7 KiB compressed |
| Languages | 100+ (modular, pick what you need) |
| Speed | 0.5-0.7ms per operation (fastest) |
| Themes | 8 built-in + community themes |
| Diff plugin | Yes - `diff-highlight` plugin |

**Pros**:
- Fastest client-side option
- Tiny bundle, CDN-loadable (no build step needed)
- Dedicated diff-highlight plugin: `language-diff-php` etc.
- Modular - load only needed languages
- Line numbers plugin available

**Cons**:
- Must run after DOM updates (Livewire lazy loading = re-run on each load)
- Less accurate than TextMate-based highlighters
- Client-side processing on large diffs could cause jank

**Fit for rfa**: Good. CDN-friendly, has diff plugin. But needs Alpine.js integration to trigger after Livewire DOM updates.

---

### 6. highlight.js

| Aspect | Details |
|--------|---------|
| Bundle | 15.6 KiB compressed |
| Languages | 192 |
| Speed | 1.1-1.4ms per operation |
| Themes | Multiple built-in |
| Auto-detect | Yes |

**Pros**:
- Broadest language support
- Auto-detection (useful when file extension ambiguous)
- CDN available

**Cons**:
- 2x slower than Prism.js
- No built-in diff support
- Same DOM timing issues as Prism.js

**Fit for rfa**: Acceptable but Prism.js is better fit.

---

### 7. Shiki

| Aspect | Details |
|--------|---------|
| Bundle | 279.8 KiB compressed (includes WASM) |
| Languages | 100+ (TextMate grammars) |
| Speed | 3.5-5.0ms per operation |
| Quality | VS Code-level (best accuracy) |
| Diff support | Yes - via @shiki/transformers |

**Pros**:
- Best highlighting accuracy (VS Code engine)
- Dual light/dark theme support
- Diff transformers with CSS class output
- Platform agnostic (browser, Node, any runtime)

**Cons**:
- **Large bundle** (280 KiB + WASM)
- 7x slower than Prism.js
- Complex setup for browser use
- Overkill for a local dev tool

**Fit for rfa**: The quality is great but the weight and complexity don't match rfa's minimal approach.

---

## Comparison Matrix

| | Phiki | Tempest | highlight.php | Prism.js | highlight.js | Shiki |
|---|---|---|---|---|---|---|
| **Side** | Server | Server | Server | Client | Client | Client |
| **Languages** | 200+ | ~15 | 185 | 100+ | 192 | 100+ |
| **Quality** | Best | Good | Moderate | Good | Good | Best |
| **Speed** | ~100ms | Fast | Moderate | 0.5ms | 1.1ms | 3.5ms |
| **Build step** | No | No | No | No | No | No |
| **Diff support** | No | Partial | No | Plugin | No | Plugin |
| **Bundle/Dep** | Composer | Composer | Composer | CDN 12KB | CDN 16KB | CDN 280KB |
| **Maintenance** | Active | Active | Stale | Active | Active | Active |

---

## Analysis for rfa

### Key decision: Server-side vs Client-side

**Server-side (PHP)** advantages:
- Highlighting happens once, result cached with the parsed diff
- No flicker/FOUC - HTML arrives pre-highlighted
- Works perfectly with Livewire lazy loading (no post-render hooks)
- Fits rfa's existing caching architecture (`DiffCacheKey::for()`)
- No additional JS payload

**Client-side (JS)** advantages:
- Language detection can use file extension from the DOM
- Doesn't slow down server response time
- Themes are pure CSS (easy to swap)
- Prism's diff-highlight plugin is purpose-built

### The diff highlighting challenge

rfa renders **individual diff lines**, not complete code blocks. This means:
- Server-side: Must highlight the full file/hunk context, then split back into lines
- Client-side: Same issue - need contiguous code for accurate tokenization
- Either way, need to tokenize at the hunk level (not per-line) for accurate results

### Recommended approach for rfa

**Primary: Phiki (server-side)**
- Best accuracy, zero dependencies, Composer-only
- Highlight at hunk level during `DiffParser` processing, store highlighted HTML per line
- Cache highlighted output alongside parsed diffs (already have caching infra)
- No client-side complexity, no FOUC, works with lazy loading for free
- VS Code themes match the GitHub-style aesthetic rfa already uses

**Alternative: Prism.js (client-side)**
- If server-side perf is a concern, Prism.js via CDN is the lightest client option
- Use `diff-highlight` plugin for diff-aware syntax highlighting
- Trigger via Alpine.js `x-init` or Livewire hook after lazy load
- Simpler integration (just add CDN + init script) but less clean UX

---

## Summary

| Approach | Recommendation |
|----------|---------------|
| **Best fit** | Phiki - server-side, zero deps, best quality, fits caching arch |
| **Lightweight alternative** | Prism.js via CDN - fast, has diff plugin, no build step |
| **Not recommended** | Torchlight (needs internet), Shiki (too heavy), highlight.php (aging) |
| **Viable but limited** | Tempest - fast but too few languages for general code review |
