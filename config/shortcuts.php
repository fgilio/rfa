<?php

declare(strict_types=1);

use App\Events\HardReloadShortcutPressed;
use App\Events\RefreshShortcutPressed;
use App\Events\ZoomShortcutPressed;

/*
|--------------------------------------------------------------------------
| Keyboard shortcuts: single source of truth
|--------------------------------------------------------------------------
|
| Every documented shortcut lives here. Three consumers read this catalog so
| the combo and label are defined exactly once:
|
|   1. The Alpine `$store.shortcuts` (public/js/shortcuts-store.js). Call
|      sites register handlers by `id`, never by a literal combo, e.g.
|      `$store.shortcuts.register('project-picker.toggle', () => toggle())`.
|      The combo string and the behaviour flags come from here: set
|      `allowInEditable` to keep firing while the caret is in an input, and
|      `ignoreAutoRepeat` to collapse a held chord to its first keydown (what
|      a toggle wants; navigation shortcuts want the repeats).
|   2. The cheat-sheet modal (`<x-shortcuts-help>`), opened with `?`, renders
|      this catalog grouped by `group`.
|   3. The native menu (NativeAppServiceProvider) reads `accelerator` for the
|      menu items it owns. App-level global shortcuts (refresh, zoom) keep
|      their Electron accelerators on the Event classes. We reference those
|      constants here so the catalog never drifts from the registry.
|
| Combo notation (mac-only):
|   - `⌘`/`⇧` glyphs = Cmd / Shift modifiers (keymap-store also treats ⌘ as
|     Ctrl so the browser dev build keeps working off-mac).
|   - `⌃`/`⌥` glyphs = Control / Option. A combo naming either one is matched
|     literally, flag for flag, with no ⌘→Ctrl aliasing — that is what makes
|     hyper (`⌃⌥⇧⌘`) expressible. Glyphs are written in Apple's order
|     (Control, Option, Shift, Command); matching is order-independent.
|   - `↵` = Enter/Return.
|   - A bare character ('j', '/', '[') matches that exact `event.key`. Its
|     shifted form is written as the produced character: '⇧C' for Shift+C,
|     '?' for Shift+/. `display` overrides the label shown in the cheat sheet.
|
| `wired` documents how the shortcut reaches its handler. `keymap` shortcuts
| flow through `$store.shortcuts.register`. `native` ones are owned by Electron
| (menu or globalShortcut) and are documentation-only on the JS side. `custom`
| ones have a bespoke renderer handler (find-in-page).
|
*/

return [

    // Display order of the groups in the cheat sheet.
    'groups' => ['Navigation', 'Review', 'Editing', 'View', 'Help'],

    'shortcuts' => [

        // -- Navigation --
        'project-picker.toggle' => [
            'combo' => '⌘K',
            'label' => 'Switch repository',
            'group' => 'Navigation',
            'allowInEditable' => true,
            'wired' => 'keymap',
        ],
        'comments-drawer.toggle' => [
            'combo' => '⌘J',
            'label' => 'Toggle comments',
            'group' => 'Navigation',
            'wired' => 'keymap',
        ],
        'branch-explorer.toggle' => [
            'combo' => '⌘B',
            'label' => 'Switch branch',
            'group' => 'Navigation',
            'wired' => 'keymap',
        ],

        // -- Review --
        'review.filter' => [
            'combo' => '/',
            'label' => 'Focus file filter',
            'group' => 'Review',
            'wired' => 'keymap',
        ],
        'review.next-file' => [
            'combo' => 'j',
            'label' => 'Next file',
            'group' => 'Review',
            'wired' => 'keymap',
        ],
        'review.prev-file' => [
            'combo' => 'k',
            'label' => 'Previous file',
            'group' => 'Review',
            'wired' => 'keymap',
        ],
        'review.comment-selection' => [
            'combo' => 'c',
            'label' => 'Comment on selection',
            'group' => 'Review',
            'wired' => 'keymap',
        ],
        'review.collapse-all' => [
            'combo' => 'C',
            'display' => '⇧C',
            'label' => 'Collapse all files',
            'group' => 'Review',
            'wired' => 'keymap',
        ],
        'review.expand-all' => [
            'combo' => 'E',
            'display' => '⇧E',
            'label' => 'Expand all files',
            'group' => 'Review',
            'wired' => 'keymap',
        ],
        'review.prev-commit' => [
            'combo' => '[',
            'label' => 'Previous commit',
            'group' => 'Review',
            'wired' => 'keymap',
        ],
        'review.next-commit' => [
            'combo' => ']',
            'label' => 'Next commit',
            'group' => 'Review',
            'wired' => 'keymap',
        ],

        // -- Editing --
        'comment.save' => [
            'combo' => '⌘↵',
            'label' => 'Save comment',
            'group' => 'Editing',
            'allowInEditable' => true,
            'wired' => 'keymap',
        ],
        'review.undo' => [
            'combo' => '⌘Z',
            'label' => 'Undo last action',
            'group' => 'Editing',
            'wired' => 'keymap',
        ],

        // -- View / App --
        'sidebar.toggle' => [
            // Hyper+S, the same chord Franco's Hammerspoon config maps to
            // "toggle sidebar" in every other app. RFA isn't in that config's
            // app list, so the chord arrives here through its pass-through.
            'combo' => '⌃⌥⇧⌘S',
            'label' => 'Toggle sidebar',
            'group' => 'View',
            // A chrome command with no text-input meaning: it has to work while
            // the caret sits in the file filter or a comment textarea.
            'allowInEditable' => true,
            // A toggle, so only the first keydown of a held chord counts.
            // Without this the sidebar flips once per auto-repeat and settles
            // on whichever parity the key release happens to land in.
            'ignoreAutoRepeat' => true,
            'wired' => 'keymap',
        ],
        'app.refresh' => [
            'combo' => '⌘R',
            'label' => 'Refresh',
            'group' => 'View',
            'allowInEditable' => true,
            'accelerator' => RefreshShortcutPressed::KEY,
            'wired' => 'keymap',
        ],
        'app.hard-reload' => [
            'combo' => '⌘⇧R',
            'label' => 'Hard reload',
            'group' => 'View',
            'allowInEditable' => true,
            'accelerator' => HardReloadShortcutPressed::KEY,
            'wired' => 'keymap',
        ],
        'app.find' => [
            'combo' => '⌘F',
            'label' => 'Find in page',
            'group' => 'View',
            'wired' => 'custom',
        ],
        'app.zoom-in' => [
            'combo' => '⌘+',
            'label' => 'Zoom in',
            'group' => 'View',
            'accelerator' => ZoomShortcutPressed::ZOOM_IN,
            'wired' => 'native',
        ],
        'app.zoom-out' => [
            'combo' => '⌘-',
            'label' => 'Zoom out',
            'group' => 'View',
            'accelerator' => ZoomShortcutPressed::ZOOM_OUT,
            'wired' => 'native',
        ],
        'app.zoom-reset' => [
            'combo' => '⌘0',
            'label' => 'Reset zoom',
            'group' => 'View',
            'accelerator' => ZoomShortcutPressed::RESET,
            'wired' => 'native',
        ],
        'app.add-repo' => [
            'combo' => '⌘O',
            'label' => 'Add repository',
            'group' => 'View',
            'accelerator' => 'CmdOrCtrl+O',
            'wired' => 'native',
        ],
        'app.review-code' => [
            'combo' => '⌘⇧C',
            'label' => 'Review code',
            'group' => 'View',
            'accelerator' => 'CmdOrCtrl+Shift+C',
            'wired' => 'native',
        ],
        'app.context-files' => [
            'combo' => '⌘⇧K',
            'label' => 'Review agents instructions',
            'group' => 'View',
            'accelerator' => 'CmdOrCtrl+Shift+K',
            'wired' => 'native',
        ],

        // -- Help --
        'help.shortcuts' => [
            'combo' => '?',
            'label' => 'Show keyboard shortcuts',
            'group' => 'Help',
            'wired' => 'keymap',
        ],
    ],
];
