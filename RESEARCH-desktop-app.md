# Research: Packaging rfa as a macOS Desktop App

## Context

**rfa** is a local code review tool for AI agent changes, built with Laravel 12 + Livewire 4 + Alpine.js + Tailwind CSS (CDN). It currently runs via `php artisan serve` and opens in the user's browser. The goal is to explore packaging it as a native macOS desktop application.

### Constraints & Preferences
- **Developer profile**: Solo developer, very familiar with Laravel/PHP
- **Planned features**: Queues and other Laravel-native features
- **Frontend**: Livewire (already in use)
- **Target platform**: macOS only (initially)
- **License**: Undecided (maybe open source)
- **Timeline**: No deadline

---

## TL;DR Recommendation

**Use NativePHP with the Electron driver.** It is the only production-ready, Laravel-native option that gives you a real desktop app with full Livewire compatibility, queue support, native OS features, and zero friction with your existing codebase. The Tauri path is not viable today for a PHP/Laravel app.

---

## The Options at a Glance

| Approach | App Size | Native Window | Livewire | Queues | macOS | Status |
|---|---|---|---|---|---|---|
| **NativePHP (Electron)** | ~120MB | Yes | Excellent | Built-in | Full | **Production-ready (v2)** |
| **NativePHP (Tauri)** | ~60-80MB | Yes | Excellent | Built-in | Full | **Paused indefinitely** |
| **tauri-php (FrankenPHP)** | ~60-100MB | Yes | Likely works | Manual | Full | Community project |
| **Platypus + static PHP** | ~35-50MB | WebKit view | Needs testing | No | macOS only | Mature tool |
| **Bash .app wrapper** | ~30-50MB | No (browser) | Excellent | Yes (manual) | Native | DIY pattern |
| **Electron + PHP sidecar (DIY)** | ~150-300MB | Yes | Excellent | Manual | Full | DIY, complex |
| **PWA** | ~0 | Partial | Needs server | Yes (manual) | Via browser | Mature standard |
| **PHP-WASM** | ~30-50MB | No (browser) | Experimental | No | Via browser | Experimental |
| **Docker** | ~1GB+ | No | Excellent | Yes | Via Docker | Wrong tool |
| **PHP-Desktop** | N/A | N/A | N/A | N/A | **None** | Abandoned |

---

## 1. NativePHP (Electron Driver) — Recommended

### What It Is
NativePHP is a Laravel package that wraps your existing app into a native desktop application using Electron. You install it via Composer, configure windows/menus in a service provider, and run `php artisan native:serve`. It bundles a static PHP 8.4 binary so end users need nothing installed.

### Why It's the Best Fit for rfa

**It's Laravel-native.** You `composer require nativephp/desktop`, publish a config, and your existing Livewire app runs in a native window. No rewrite, no new framework to learn, no JavaScript glue code.

**Queues work out of the box.** NativePHP automatically creates the jobs table migration, boots a queue worker on startup, and lets you configure workers in `config/nativephp.php`. This is critical since you plan to leverage queues.

**Livewire works seamlessly.** NativePHP runs a local PHP server inside the Electron shell. The Electron window points to `localhost`. Livewire makes its AJAX requests to this local server exactly as it does in a browser. Zero changes needed.

**Native OS access from PHP.** From any Livewire component method:
```php
// OS notification
Notification::title('Review Complete')->message('3 comments exported')->show();

// Open URL in default browser
Shell::openExternal('https://github.com/...');

// Native file dialogs, clipboard, menus, system tray — all via PHP facades
```

**Native events in Livewire.** Listen for OS-level events directly in your components:
```php
#[OnNative('app:openFile')]
public function handleFileOpen($path) { ... }
```

### Current State
- **Desktop v2** released October 2025, declared production-ready
- Works with Laravel 12.x and PHP 8.4
- MIT licensed, free and open source
- ~3,900 GitHub stars, active Discord community
- Maintained by Marcel Pociot (BeyondCode) and Simon Hamp
- Laracasts course available

### Limitations
- **App size ~120MB+** (Electron bundles Chromium — this is unavoidable)
- **SQLite only** for the bundled database (perfect for rfa, which already uses SQLite)
- **Source code ships with the app** — no built-in obfuscation (Zephpyr build service planned but not GA)
- **Cross-compilation limited** — build macOS apps on macOS
- **Electron API coverage incomplete** — not all Electron features have PHP facades yet

### What It Means for rfa
Your existing codebase would need minimal changes:
1. `composer require nativephp/desktop`
2. Create a `NativeAppServiceProvider` to configure the window size, title, and menu
3. Your existing `ReviewPage` and `DiffFile` Livewire components work as-is
4. The `rfa` bash wrapper would be replaced by the native app launcher
5. `RFA_REPO_PATH` could be set via a native file dialog or drag-and-drop

---

## 2. Tauri — Honest Assessment

### The Headline Advantages of Tauri (in General)
- **10-30x smaller bundles** (~2-10MB vs ~120MB for Electron)
- **5-8x less memory** (~30-40MB idle vs ~200-300MB)
- **Faster startup** (under 0.5s vs 1-2s)
- **Security by default** (capability-based permissions, everything disabled by default)
- **Mobile support** (Android + iOS from v2)
- **No bundled browser engine** — uses the OS's native WebView (WKWebView on macOS)

### Why These Advantages Largely Evaporate for a PHP App

**The size advantage disappears.** Tauri's small size comes from not bundling a browser engine. But you still need to bundle a PHP runtime (~30-50MB with FrankenPHP or static-php-cli). A Tauri + PHP app would be ~60-100MB — still smaller than Electron (~120MB), but not the dramatic 2MB headline figure. The savings go from "30x smaller" to "maybe 1.5x smaller."

**You're running two runtimes anyway.** In a Tauri + PHP setup, you have a Rust process managing the window AND a PHP process running Laravel. This erodes Tauri's memory advantage. You have two processes to manage, two crash points, two sets of logs.

**PHP is not a first-class citizen.** Tauri is designed for Rust + JavaScript. Using PHP requires:
- Bundling PHP as a "sidecar" binary
- Managing the PHP process lifecycle from Rust
- Dealing with a three-layer architecture (WebView ↔ Rust ↔ PHP)
- Learning enough Rust to configure sidecars and handle process management

**NativePHP's Tauri driver is paused indefinitely.** The NativePHP team originally started with Tauri but abandoned it because:
- Neither maintainer knows Rust deeply
- Tauri 2.0 disrupted their work mid-development
- Funding applications to the Tauri team fell through
- Tauri's architecture conflicts with how PHP apps work (PHP needs a web server; Tauri's design avoids web servers)

**The security model doesn't help with PHP.** Tauri's capability-based security model governs the WebView ↔ Rust boundary. But the PHP sidecar runs outside this sandbox with full system access. The security architecture you'd actually rely on is Laravel's, not Tauri's.

**WebView inconsistencies.** Tauri uses WKWebView (WebKit/Safari engine) on macOS. Livewire should work fine in WebKit, but you lose the guaranteed Chromium consistency that Electron provides. If you've been testing in Chrome, subtle differences may appear.

### When Tauri Would Make Sense
- If you were building a Rust or JavaScript backend (not PHP)
- If bundle size under 10MB was a hard requirement
- If you needed iOS/Android from the same codebase
- If the NativePHP Tauri driver gets revived (no timeline)

### Available Tauri + PHP Options Today
| Project | Approach | Status |
|---|---|---|
| `mucan54/tauri-php` | FrankenPHP inside Tauri | Community project, active |
| `austenc/tauravel` | PHP dev server + Tauri | Proof-of-concept |
| NativePHP Tauri driver | Laravel package | **Paused/shelved** |
| Manual sidecar | Bundle PHP binary yourself | DIY, requires Rust knowledge |

### Bottom Line on Tauri
Tauri is an excellent framework — for Rust + JavaScript apps. For a Laravel + Livewire app, it's a square peg in a round hole. The theoretical advantages (size, memory, security) are substantially eroded by the need to bundle and manage a PHP runtime. And the ecosystem support for PHP on Tauri is fragmented and immature compared to NativePHP's Electron driver.

---

## 3. Other Notable Options

### Platypus + Static PHP Binary
[Platypus](https://sveinbjorn.org/platypus) is a mature macOS tool (20+ years, v5.5.0 December 2025) that wraps scripts into `.app` bundles. It has a WebKit Web View output mode that could render your app in a native window.

**Pros**: Tiny (~35MB), native macOS app, no framework overhead, free/open source.
**Cons**: WebKit view needs Livewire testing, no queue worker management, no native API facades, macOS-only by design. No auto-updater.

**Good for**: A lightweight, macOS-only distribution if you don't need native OS features beyond a window.

### Bash/AppleScript .app Wrapper
Create a `.app` bundle manually with a bash launcher that starts `php artisan serve` and opens the browser. This is essentially what your current `rfa` bash script does, but packaged as a clickable macOS app.

**Pros**: Maximum simplicity, zero dependencies, full Laravel feature support (queues etc. if you start workers).
**Cons**: Opens in the user's browser (not a dedicated window), no native OS integration.

**Good for**: Power users / developer distribution where a polished native feel isn't critical.

### FrankenPHP Standalone Binary
Compile your entire Laravel app into a single executable (~50MB) using FrankenPHP's application embedding. Users download and run one file.

**Pros**: Single binary, HTTP/2/3, Laravel Octane support, backed by PHP Foundation.
**Cons**: Still opens in browser, no native window, macOS builds less tested.

### PWA (Progressive Web App)
Add `erag/laravel-pwa` package for an installable web app experience. On macOS, the "installed" PWA appears in the Dock.

**Pros**: Near-zero effort, works with existing codebase.
**Cons**: Just a browser shortcut, requires the server to be running, no native features. Not a real desktop app.

### PHP-WASM
Run PHP entirely in the browser via WebAssembly. PlayWithLaravel.com demonstrates this with Laravel + Livewire.

**Pros**: Truly serverless, no PHP installation needed.
**Cons**: Experimental, no queues, no background processing, filesystem is in-memory. Not production-ready for a full Livewire app.

---

## 4. What rfa Specifically Needs

Given rfa's architecture and planned features, here's what matters:

| Requirement | NativePHP (Electron) | Tauri + PHP | Platypus | Bash .app |
|---|---|---|---|---|
| Livewire components work | Yes | Likely (WebKit) | Needs testing | Yes (browser) |
| Laravel queues | Built-in | Manual | No | Manual |
| SQLite database | Yes | Yes | Yes | Yes |
| Native window (not browser) | Yes | Yes | WebKit view | No |
| macOS notifications | PHP facade | Rust/Swift code | No | No |
| File dialogs | PHP facade | Rust/Swift code | No | No |
| System tray | PHP facade | Rust/Swift code | No | No |
| Auto-updater | Electron built-in | Tauri built-in | No | No |
| Code signing/notarization | Supported | Supported | Possible | Possible |
| App size | ~120MB | ~60-100MB | ~35-50MB | ~30-50MB |
| Setup complexity | Low (Composer package) | High (Rust + sidecar) | Medium | Low |
| Solo developer friendly | Very | Not really | Yes | Yes |

---

## 5. Recommended Path

### Phase 1: Keep the Current Architecture
Your `rfa` bash script + `php artisan serve` + browser approach works. For a solo developer tool, this is perfectly fine. Focus on building features (queues, etc.) first.

### Phase 2: Add NativePHP When Ready for Desktop
When you want a proper desktop app experience:
1. `composer require nativephp/desktop`
2. Create `NativeAppServiceProvider` with window config
3. Replace the `rfa` bash wrapper with native app launch
4. Add native file dialog for repo selection (instead of CLI argument)
5. Add system tray icon for background operation (if needed for queue workers)
6. Build and distribute as a `.dmg`

### Why Not Start with NativePHP Now?
You could — it's production-ready. But since you're a solo developer with no timeline and the tool works fine as a web app, adding NativePHP is best done when:
- You have features that benefit from native OS integration (notifications, file dialogs, system tray)
- You want to distribute to users who shouldn't need to install PHP
- You want a polished, app-store-like experience

The migration from "Laravel web app" to "NativePHP desktop app" is designed to be incremental — your Livewire components don't change.

---

## References

- [NativePHP Documentation](https://nativephp.com/docs/desktop/2/getting-started/introduction)
- [NativePHP Desktop v2 Release](https://nativephp.com/blog/nativephp-for-desktop-v2-released)
- [NativePHP Queues](https://nativephp.com/docs/desktop/1/digging-deeper/queues)
- [Tauri v2 Documentation](https://v2.tauri.app/start/)
- [Tauri vs Electron Comparison](https://www.gethopp.app/blog/tauri-vs-electron)
- [mucan54/tauri-php](https://github.com/mucan54/tauri-php)
- [Platypus](https://sveinbjorn.org/platypus)
- [FrankenPHP Laravel](https://frankenphp.dev/docs/laravel/)
- [Static PHP CLI](https://static-php.dev/)
- [PlayWithLaravel (PHP-WASM demo)](https://playwithlaravel.com/)
- [NativePHP GitHub](https://github.com/NativePHP/laravel)
- [Laracasts NativePHP Series](https://laracasts.com/series/build-native-apps-with-php)
