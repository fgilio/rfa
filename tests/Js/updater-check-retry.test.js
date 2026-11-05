import { EventEmitter } from 'node:events';
import { readFileSync } from 'node:fs';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const patchSource = readFileSync('scripts/patch-nativephp.php', 'utf8');
const functionSource = patchSource.slice(
    patchSource.indexOf('function rfaPatchUpdaterCheckRetry'),
    patchSource.indexOf('function rfaPatchLazyContextMenu'),
);
const helperBlock = functionSource.match(/\$helper = <<<'JS'\n([\s\S]*?)\nJS;/)?.[1];

if (!helperBlock) throw new Error('Unable to find the generated updater check retry block');

const { rfaForwardUpdaterErrors, rfaIsTransientUpdaterError } = new Function(
    `${helperBlock}\nreturn { rfaForwardUpdaterErrors, rfaIsTransientUpdaterError };`,
)();

/**
 * Mirrors electron-updater's GitHub check error: the provider wraps the
 * HttpError, stack included, in the message of a plain Error.
 */
function githubFeedError(statusCode) {
    return Object.assign(
        new Error(`Cannot parse releases feed: Error: Unable to find latest version on GitHub (https://github.com/fgilio/rfa/releases/latest), please ensure a production release exists: HttpError: ${statusCode} \nHeaders: {}`),
        { code: 'ERR_UPDATER_INVALID_RELEASE_FEED' },
    );
}

/**
 * An EventEmitter shaped like electron-updater's AppUpdater: every check
 * emits 'checking-for-update', then the outcome the test queued for it.
 */
function createHarness(outcomes) {
    const updater = new EventEmitter();
    const forwardedErrors = [];

    updater.checkForUpdates = vi.fn(() => {
        updater.emit('checking-for-update');
        const outcome = outcomes.shift() ?? 'not-available';

        const check = Promise.resolve().then(() => {
            if (outcome instanceof Error) {
                updater.emit('error', outcome);
                throw outcome;
            }

            updater.emit(`update-${outcome}`, { version: '0.30.0' });

            return { isUpdateAvailable: outcome === 'available' };
        });
        check.catch = vi.fn(Promise.prototype.catch.bind(check));

        return check;
    });

    rfaForwardUpdaterErrors(updater, (error) => forwardedErrors.push(error));

    return {
        updater,
        forwardedErrors,
        check: () => updater.checkForUpdates().catch(() => {}),
    };
}

describe('generated updater check retry', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('retries a check that failed on a transient GitHub error instead of reporting it', async () => {
        const harness = createHarness([githubFeedError(529), 'not-available']);

        await harness.check();

        expect(harness.forwardedErrors).toEqual([]);
        expect(harness.updater.checkForUpdates).toHaveBeenCalledOnce();

        await vi.advanceTimersByTimeAsync(2000);

        expect(harness.updater.checkForUpdates).toHaveBeenCalledTimes(2);
        expect(harness.forwardedErrors).toEqual([]);
    });

    it('backs off 2, 5 and 15 seconds and reports the fourth failure once', async () => {
        const lastFailure = githubFeedError(503);
        const harness = createHarness([githubFeedError(529), githubFeedError(502), githubFeedError(429), lastFailure]);

        await harness.check();
        await vi.advanceTimersByTimeAsync(1999);
        expect(harness.updater.checkForUpdates).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(1);
        expect(harness.updater.checkForUpdates).toHaveBeenCalledTimes(2);

        await vi.advanceTimersByTimeAsync(5000);
        expect(harness.updater.checkForUpdates).toHaveBeenCalledTimes(3);
        expect(harness.forwardedErrors).toEqual([]);

        await vi.advanceTimersByTimeAsync(15000);
        expect(harness.updater.checkForUpdates).toHaveBeenCalledTimes(4);
        expect(harness.forwardedErrors).toEqual([lastFailure]);

        await vi.advanceTimersByTimeAsync(60000);
        expect(harness.updater.checkForUpdates).toHaveBeenCalledTimes(4);
        expect(harness.forwardedErrors).toEqual([lastFailure]);
    });

    it('reports a non-transient check error at once', async () => {
        const failure = new Error('No published versions on GitHub');
        const harness = createHarness([failure]);

        await harness.check();
        await vi.advanceTimersByTimeAsync(60000);

        expect(harness.forwardedErrors).toEqual([failure]);
        expect(harness.updater.checkForUpdates).toHaveBeenCalledOnce();
    });

    it('reports a transient download error at once', async () => {
        const harness = createHarness(['available']);
        const downloadFailure = new Error('net::ERR_CONNECTION_RESET');

        await harness.check();
        harness.updater.emit('error', downloadFailure);
        await vi.advanceTimersByTimeAsync(60000);

        expect(harness.forwardedErrors).toEqual([downloadFailure]);
        expect(harness.updater.checkForUpdates).toHaveBeenCalledOnce();
    });

    it('gives the next check a fresh set of retries after a successful one', async () => {
        const harness = createHarness([
            githubFeedError(529), githubFeedError(529), 'not-available',
            githubFeedError(529), githubFeedError(529), githubFeedError(529), 'not-available',
        ]);

        await harness.check();
        await vi.advanceTimersByTimeAsync(2000 + 5000);
        expect(harness.updater.checkForUpdates).toHaveBeenCalledTimes(3);

        await harness.check();
        await vi.advanceTimersByTimeAsync(2000 + 5000 + 15000);

        expect(harness.updater.checkForUpdates).toHaveBeenCalledTimes(7);
        expect(harness.forwardedErrors).toEqual([]);
    });

    it('lets a check started during the backoff replace the pending retry', async () => {
        const harness = createHarness([githubFeedError(529), 'not-available']);

        await harness.check();
        await vi.advanceTimersByTimeAsync(1000);
        await harness.check();
        await vi.advanceTimersByTimeAsync(60000);

        expect(harness.updater.checkForUpdates).toHaveBeenCalledTimes(2);
        expect(harness.forwardedErrors).toEqual([]);
    });

    it('handles the rejection of a failed retry', async () => {
        const harness = createHarness([githubFeedError(529), githubFeedError(529)]);

        await harness.check();
        await vi.advanceTimersByTimeAsync(2000);

        const retry = harness.updater.checkForUpdates.mock.results[1].value;

        expect(retry.catch).toHaveBeenCalledOnce();
    });

    it.each([
        ['HttpError 5xx in the message', githubFeedError(529)],
        ['HttpError 429 in the message', githubFeedError(429)],
        ['HttpError status code', Object.assign(new Error('503 Service Unavailable'), { name: 'HttpError', statusCode: 503 })],
        ['Chromium network error', new Error('net::ERR_INTERNET_DISCONNECTED')],
        ['timed out socket', Object.assign(new Error('connect ETIMEDOUT 140.82.112.3:443'), { code: 'ETIMEDOUT' })],
        ['reset socket', new Error('read ECONNRESET')],
        ['failed DNS lookup', new Error('getaddrinfo ENOTFOUND github.com')],
        ['temporary DNS failure', new Error('getaddrinfo EAI_AGAIN github.com')],
    ])('treats a %s as transient', (_, error) => {
        expect(rfaIsTransientUpdaterError(error)).toBe(true);
    });

    it.each([
        ['HttpError 404 in the message', githubFeedError(404)],
        ['HttpError status code 403', Object.assign(new Error('403 Forbidden'), { name: 'HttpError', statusCode: 403 })],
        ['missing release', new Error('No published versions on GitHub')],
        ['signature failure', new Error('Could not get code signature for running application')],
    ])('treats a %s as permanent', (_, error) => {
        expect(rfaIsTransientUpdaterError(error)).toBe(false);
    });
});
