# Find and fix the 3 highest-impact UX paper cuts in this codebase

## What I want you to do

Explore the codebase, identify undeniable UX paper cuts, prioritize them, and **fix only the top 3**. Keep changes surgical, cohesive with existing patterns, and CI-green.

## What "UX paper cut" means

A small, objectively-wrong UX issue — not a stylistic preference. The concept is the *paper cut*: tiny, easy to miss, never quite enough to file, but it nicks the user every single time they brush past it.

The vision: **the interface should never lie about what it is or what it will do, and it should feel like one product.** A control should announce what it does, look like what it does, behave like what it does, and tell you what happened. Sibling surfaces should cohere — same affordance, same rhythm, same vocabulary, same micro-interaction. When any of that breaks, that's a paper cut.

Concretely, paper cuts tend to live at the seams between:

- **What's shown vs. what's said** — the visual affordance and the assistive-tech announcement disagree, or one of them is missing entirely.
- **What's promised vs. what's delivered** — the cursor, label, hint, or motion implies one outcome and the click does another (or nothing).
- **What just happened vs. what the user can perceive** — the system completed an action but gave no acknowledgement, or it failed and gave no diagnosis, or it transitioned without the motion that would carry the eye through the change.
- **What one screen does vs. what its sibling does** — the same affordance behaves, looks, animates, or sounds differently in two places that the user thinks of as the same thing. Visual rhythm (spacing, density, alignment) and interaction rhythm (timing, easing, hover/focus/active states) should be consistent across the family.
- **What the language says vs. what the data says** — singular/plural disagreement, casing inconsistency, copy that's stale relative to the action, button verbs that don't predict the next state.
- **What the developer assumed vs. what the framework actually does** — a pre-existing wrapper, listener, directive, or attribute is missing from a sibling surface, and a feature silently no-ops there.
- **What the eye expects vs. what the pixels do** — micro-interactions that should exist and don't (hover/focus/pressed/disabled states, loading affordances, empty states, transitions on enter/exit/reorder), or that exist but feel wrong (jank, abrupt cuts, transitions that fight the user's action, motion that ignores `prefers-reduced-motion`).
- **What the chrome promises vs. what the surface delivers** — visual cohesiveness drift across the codebase: same kind of element rendered with subtly different padding, radius, weight, color token, icon size, badge style, divider style, or shadow in different sections of the app, when there's clearly a shared primitive available.

Let your latent intuition for these seams drive the survey. Don't pattern-match to a checklist.

What does *not* qualify: redesign asks, palette opinions, layout taste, anything that needs a new abstraction, anything a designer would need to weigh in on.

## How to work

### 1. Explore first

- Read every documentation/conventions file the project ships at the root and in subdirectories (anything that looks like project memory, architecture notes, agent instructions, or "known debt"). They encode the team's conventions and the issues you should *not* re-litigate.
- Get a feel for the entry points: which screens exist, which components compose them, what overlays/drawers/dialogs are wired up.
- Skim the components directory and note the existing vocabulary (reusable wrappers, confirm patterns, hint primitives, etc.).
- Look at recent commits to see what the team has been merging — that tells you the bar and the style.
- Consider delegating the survey to a sub-agent so you don't pollute your main context with full-file reads.

### 2. Identify candidates

Cast a wide net first. Aim for ~10–15 raw candidates. For each, capture:

- **Issue** (one sentence — what's wrong from the user's perspective)
- **Location** (`file_path:line_number`)
- **Proposed fix** (one sentence — what specifically to change)
- **Risk** (single-line: any behavior change, any test that asserts on this, any documented convention it brushes against)

Then **verify each candidate by reading the surrounding code** before trusting it. Sub-agents will sometimes flag false positives (e.g. claim a button is icon-only when it has visible text, or claim a hint is missing when the framework derives one from a parent). Always open the actual file before adding a candidate to the shortlist.

Skip anything the project's conventions explicitly reject. If the docs say "we prefer undo over confirm dialogs" or "we use sentence case", don't suggest the opposite.

### 3. Prioritize to 3

Rank candidates by **impact × ease × safety**. Prefer:

- Issues that affect a feature users hit on every session over rare ones.
- Real bugs (e.g. a handler that's missing, so a feature doesn't work) over polish (e.g. an accessible name on a control that already has visible text and a tooltip).
- Fixes that touch one or two files cohesively over fixes that ripple across many files.
- Fixes where the existing pattern is obvious (other instances of the same fix already in the codebase) over fixes that would establish a new convention.

State your top 3 with a one-line rationale before you start coding. If two candidates are very close, pick the safer one.

### 4. Fix the 3 — carefully

Hard rules:

- **No new patterns.** Use existing components, helpers, and conventions. If you're about to introduce a new wrapper, service, or abstraction, stop — either the project already has one (search for it) or your fix is too ambitious for this scope.
- **No breaking changes to behavior.** A paper-cut fix is additive (an accessible name, a hover hint, a missing toast, a missing event listener, a missing motion state) or a one-line correctness fix (pluralization, ternary, copy fix). Not a refactor.
- **No performance regressions.** Be especially careful about anything inside list/loop/render hot paths — work added there can run thousands of times per page. Before pushing, run any perf benchmark the project ships and eyeball the result. If a hot path forces a tradeoff, prefer native language primitives over framework conveniences and leave a one-line "why" comment so the next reader doesn't "fix" your "non-idiomatic" choice back.
- **Match existing test coverage.** If a unit/integration/browser test asserts on a string or selector you're changing, update the test in the same commit. Search the test directory for the literal string before editing it. End-to-end tests typically use exact-match selectors, so renaming a label, placeholder, or accessible name will silently break them.
- **Watch framework-specific attribute pre-processing.** Some component libraries pre-compile attributes (treating `:foo="bar"` as a host-language expression rather than a passthrough). Verify your reactive bindings work on both raw elements and framework components before assuming portability.
- **Watch directives that depend on companion CSS or runtime registration** (e.g. cloak/visibility directives that need a CSS rule to actually hide things). If your fix relies on one, confirm the dependency is present.
- **One commit per fix.** Each commit message should explain what the user sees differently and why the change is small enough to land safely. Don't bundle unrelated fixes.

### 5. Verify

Before each commit:

- Run the project's formatter, linter, and type checker (the conventions file should name them).
- Run the full unit/integration test suite — must stay 100% green.
- If you touched a file with browser/end-to-end test coverage, run those tests too.
- If you touched anything in a hot render path, run any perf benchmark the project ships.

After all commits:

- Push the branch.
- Do NOT open a PR unless the user explicitly asks.

## Output

Reply with:

1. The 3 fixes you picked and the one-line rationale for each (before coding).
2. As you commit each one, a one-paragraph note covering: what the user sees differently, what file(s) you touched, what you ran to verify.
3. At the end, a final summary listing the 3 commit SHAs and a one-sentence summary of each.

Be skeptical. If the survey turns up fewer than 3 truly undeniable candidates, ship fewer commits — quality over quota.
