<?php

declare(strict_types=1);

namespace App\Actions;

use Native\Desktop\Client\Client;
use Native\Desktop\Events\EventWatcher;
use Native\Desktop\Http\Middleware\PreventRegularBrowserAccess;

/**
 * Re-reads the per-launch NativePHP runtime values from the live process
 * environment and overrides them in the (potentially version-cached) config.
 *
 * NativePHP injects a fresh API port and IPC secret into the PHP server's
 * environment on every launch (`NATIVEPHP_API_URL`, `NATIVEPHP_SECRET`). They
 * are the ONLY `nativephp-internal` values that change between launches of the
 * same install — every other injected value (storage/database/user paths) is
 * install-stable. Both are read through `config('nativephp-internal.*')`:
 *
 *   - {@see Client} signs every native API call with the secret and targets the
 *     API URL — and {@see EventWatcher} constructs a long-lived Client during
 *     NativePHP's package registration, capturing both values at that moment for
 *     every custom `nativephp`-channel broadcast it later posts, and
 *   - {@see PreventRegularBrowserAccess} compares the secret on the
 *     `_native/api/booted` / `_native/api/events` requests that drive the window
 *     and native events.
 *
 * A version-cached config froze those two values at cache time, so without this
 * the launch would have to re-run `config:cache` to re-bake them. Because they
 * arrive as real process environment variables (not `.env` entries), `env()`
 * reads them live at runtime even under a cached config — so re-hydrating here
 * keeps the persisted config valid and lets the startup patch skip the
 * per-launch `config:cache` boot entirely (see scripts/patch-native-server.php).
 *
 * Run from a `beforeBootstrapping(RegisterProviders)` hook in bootstrap/app.php
 * so it lands after the config is loaded but BEFORE any provider registers —
 * NativePHP builds {@see EventWatcher}'s Client during package registration, so
 * an app provider (which registers after package providers) would be too late.
 */
final readonly class RehydrateNativeRuntimeConfigAction
{
    /**
     * config key => environment variable for each per-launch value.
     *
     * @var array<string, string>
     */
    private const RUNTIME_VALUES = [
        'nativephp-internal.api_url' => 'NATIVEPHP_API_URL',
        'nativephp-internal.secret' => 'NATIVEPHP_SECRET',
    ];

    public function handle(): void
    {
        // Only the cached config freezes these. An uncached config (dev
        // `native:run`, browser, tests) re-evaluates env() in the config file on
        // every boot, so the values are already live and this is a no-op.
        if (! app()->configurationIsCached()) {
            return;
        }

        $overrides = [];

        foreach (self::RUNTIME_VALUES as $configKey => $envKey) {
            $value = $this->readEnv($envKey);

            // Keep the cached value when the variable is absent rather than
            // blanking it — never make the bridge worse than today's behaviour.
            if ($value !== null) {
                $overrides[$configKey] = $value;
            }
        }

        if ($overrides !== []) {
            config($overrides);
        }
    }

    /**
     * Read a process environment variable directly. These are set by Electron on
     * the spawned PHP process (not via `.env`), so $_SERVER / getenv() always see
     * them — independent of the dotenv repository and of config caching.
     */
    private function readEnv(string $key): ?string
    {
        // getenv() returns false when unset; $_SERVER/$_ENV env values are always
        // strings. Anything that is not a non-empty string means "not provided".
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
