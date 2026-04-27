# JS unit tests (Vitest + happy-dom)

For loose scripts in `public/js/` that have non-trivial logic (timers, DOM
event handling, state machines), add unit tests here.

## Setup

- File pattern: `tests/Js/*.test.js` (picked up by `vitest.config.js`).
- Run: `composer test:js` (or `npm test` / `npm run test:watch`).
- Environment: `happy-dom` — provides `window`, `document`, events, but no
  network.

## Driving focus / visibility

happy-dom defaults to focused + visible. Override per test:

```js
vi.spyOn(document, 'hasFocus').mockReturnValue(false);
Object.defineProperty(document, 'hidden', { configurable: true, get: () => true });
```

## Timers

Always use fake timers for `setTimeout` / `setInterval` logic — real timers
in DOM tests are flaky:

```js
vi.useFakeTimers();
await vi.advanceTimersByTimeAsync(10_000);
```

## Importing production scripts

Scripts in `public/js/` use a UMD-style wrapper: they check for
`module.exports` at evaluation and export their internals to Node, while
still auto-installing in the browser. See `public/js/smart-poll.js` for the
canonical shape.

Import the default export and destructure:

```js
import smartPoll from '../../public/js/smart-poll.js';
const { parseDuration, createDirectiveHandler, install } = smartPoll;
```

Don't call `autoInstall(window)` from a test — the UMD wrapper only invokes
it when the script is loaded as a `<script src>` in the browser, never when
imported under Node.
