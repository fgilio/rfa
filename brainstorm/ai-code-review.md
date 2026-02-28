# AI Code Review in rfa — Brainstorm

## The Opportunity

rfa sits in a unique position: it's the **local, human-in-the-loop bridge** between AI agents and human reviewers. Today it shows diffs and lets humans write comments for agents. But what if rfa *also* ran AI analysis on those diffs — giving the human reviewer an AI "co-reviewer" that surfaces things they might miss?

This is fundamentally different from CodeRabbit/BugBot. Those tools replace or supplement the human reviewer on PRs. rfa's AI features would **augment the human who is already reviewing AI-generated code** — a reviewer reviewing AI output, assisted by AI. The human stays in control.

---

## What the Competition Does

### CodeRabbit
- Auto-reviews every PR with line-by-line comments, PR summaries, release note drafts
- Multi-platform: PR, IDE, CLI
- Agentic code validation: AST parsing, incremental analysis, security scanning
- Generates shell scripts to navigate code and extract patterns
- Custom review profiles and learnable rules
- Can generate tests, docs, open Jira/Linear issues from PR comments
- Code graph analysis for dependency understanding
- Semantic search via LanceDB
- Runs on Cloud Run, 8 vCPUs/32GB per instance, 10-20 min per review

### Cursor BugBot
- Runs 8 parallel review passes with randomized diff order per PR
- Fully agentic architecture (not a fixed pipeline) — reasons over diffs, calls tools dynamically
- Custom rules via `.cursor/BUGBOT.md` files (natural language)
- Inline one-click fixes in Cursor IDE
- 70% resolution rate, 2M+ PRs/month
- Auto-run on PRs with Linear integration

### Greptile
- Full codebase knowledge graph — indexes every function, dependency, historical change
- Deep cross-file context awareness
- Uses Claude Agent SDK for autonomous investigation
- Shows evidence from your codebase for every flagged issue
- Highest catch rate, but also highest false positive rate

### Ellipsis
- Goes beyond review: auto-generates fixes, pushes commits
- Executes proposed fixes to verify they compile and pass tests
- Bridges review ↔ implementation gap

### Industry Trend: Single-Concern Agents
The industry is moving toward **decomposed, single-concern review agents** rather than one monolithic "review everything" pass. Each agent gets a tailored prompt, domain-specific context, and outputs findings scoped to its concern. This produces higher-signal, lower-noise feedback.

---

## What rfa Should Do (Proposals)

### 1. Focused Review Lenses (Single-Concern Agents)

The killer feature: instead of one generic "AI review" button, offer **discrete review lenses** — each one runs a single, tightly-scoped AI analysis pass. The human picks which lenses to run.

**Core lenses to ship first:**

| Lens | What it looks for |
|---|---|
| **Security** | Injection risks (SQL, XSS, command), auth/authz gaps, secret exposure, insecure crypto, CSRF, mass assignment, path traversal |
| **Laravel Quality** | Correct use of Eloquent (N+1, raw queries), proper middleware, service container usage, validation rules, route model binding, config/env access patterns, facade vs injection |
| **Livewire Quality** | Proper use of wire:model, lifecycle hooks, component communication, hydration issues, security (exposed public properties), Alpine.js integration patterns |
| **Accessibility** | ARIA attributes, semantic HTML, color contrast references, keyboard navigation, focus management, screen reader compatibility, form labels |
| **Performance** | N+1 queries, missing indexes (migration review), eager loading, caching opportunities, unnecessary loops, memory usage in collections |
| **Error Handling** | Missing try/catch, swallowed exceptions, generic catches, missing validation, unhelpful error messages, missing logging |
| **Test Quality** | Test coverage gaps for changed code, assertion quality, missing edge cases, test isolation issues, brittle tests |
| **Naming & Clarity** | Misleading variable/function names, unclear intent, magic numbers, overly complex conditionals, dead code |

**How it works:**
- Human opens rfa, sees the diff as usual
- Sidebar or toolbar shows available lenses with toggle switches
- Human activates one or more lenses
- Each lens runs independently (can be parallelized)
- Results appear as AI-generated inline annotations on the diff (visually distinct from human comments — e.g., different color/icon)
- Human can dismiss, accept, or convert an AI annotation into a human comment to send back to the agent

**Why single-concern matters:**
- Less noise — a security lens won't nag about naming conventions
- Better prompts — each lens gets a domain-expert system prompt
- User control — you pick what matters for *this* review
- Parallelizable — run 3 lenses simultaneously
- Extensible — users could write custom lenses (see below)

### 2. Custom / User-Defined Lenses

Let users create their own review lenses via a simple config format:

```yaml
# .rfa/lenses/psr12.yaml
name: PSR-12 Style
description: Check for PSR-12 coding standard compliance
prompt: |
  You are a PHP code style expert focused exclusively on PSR-12 compliance.
  Review the following diff and identify any violations of PSR-12.
  Focus on: brace placement, spacing, naming conventions, use statements ordering.
  Do NOT comment on logic, security, or anything outside PSR-12.
severity: suggestion
```

```yaml
# .rfa/lenses/company-patterns.yaml
name: Acme Patterns
description: Enforce Acme Corp internal patterns
prompt: |
  You are reviewing code for compliance with Acme Corp's internal standards:
  - All API responses must use ApiResponse::success() / ApiResponse::error()
  - All jobs must implement ShouldBeUnique
  - Database queries must go through Repository classes, never directly in Controllers
  - Events must be dispatched via the EventBus, not directly
severity: warning
```

This is like BugBot's `BUGBOT.md` but more structured and per-lens rather than one global blob.

### 3. File Grouping & Categorization

AI-powered intelligent file grouping in the sidebar:

**Auto-categorize changed files into logical groups:**
- "Database Changes" — migrations, seeders, factories
- "API Layer" — controllers, routes, form requests, resources
- "Business Logic" — actions, services, models
- "Frontend" — Blade views, Livewire components, JS/CSS
- "Tests" — test files
- "Configuration" — config files, .env changes
- "Dependencies" — composer.json, package.json changes

**Beyond simple path-based grouping — AI can understand intent:**
- "Files related to the user authentication feature"
- "Files that modify the payment flow"
- "Test files and the code they test" (paired view)

**Review order suggestions:**
- "Start with migrations, then models, then the service layer, then controllers"
- Topological sort by dependency — review foundational changes first

### 4. AI-Generated Review Summary

Before diving into individual files, show a high-level AI summary:

```
## Change Summary
This diff adds a new "team invitations" feature:
- New `TeamInvitation` model with migration
- `InviteTeamMemberAction` handles the business logic
- `TeamInvitationController` with invite/accept/decline endpoints
- Mailable for sending invitation emails
- 3 new Livewire components for the invitation UI

## Risk Assessment
- **Medium risk**: New database migration adds a table — ensure rollback works
- **Low risk**: New mailable — standard Laravel patterns used correctly
- **Note**: No tests were added for the new action class

## Suggested Review Focus
1. Check the migration rollback (`down()` method)
2. Verify authorization logic in `InviteTeamMemberAction`
3. Consider rate limiting on the invite endpoint
```

### 5. AI-Powered "What Did the Agent Change and Why?" Narrator

Since rfa specifically reviews AI agent output, provide an AI that **explains what the agent likely intended**:

- "The agent appears to have refactored the `UserService` to extract payment logic into a new `PaymentService` — this is a separation of concerns change"
- "The agent added a `try/catch` around the API call in `FetchWeatherAction` — this was likely in response to your previous comment about error handling"
- "The agent deleted the `helpers.php` file and moved functions into dedicated service classes"

This gives the human reviewer *context* before they start reading diffs.

### 6. Comment-Aware Follow-Up Reviews

Since rfa tracks review sessions and previous comments, the AI can do **follow-up analysis**:

- "You commented 'add validation here' on line 42 last review. The agent added validation — here's whether it looks correct and complete."
- "You flagged a security concern in the previous session. Checking if it was addressed..."
- Track patterns: "You've commented about missing null checks 5 times across sessions. Want to add this as a permanent lens?"

### 7. Diff-Aware Static Analysis Integration

Rather than replacing tools like PHPStan/Pint/ESLint, **run them on only the changed files** and surface results inline:

- Run PHPStan on modified PHP files, show errors inline on the diff
- Run Pint and show formatting issues
- Run ESLint on changed JS files
- Run `php artisan route:list` before/after and diff the routes

The key insight: run these tools **scoped to the diff**, not the whole project. Show results as annotations on the diff view.

### 8. Cross-File Impact Analysis

When a file changes, show the human reviewer what else in the codebase might be affected:

- "This method signature changed — 4 other files call this method" (with links)
- "This migration adds a column — 2 model factories and 1 seeder may need updating"
- "This route was renamed — check if any frontend links reference the old URL"

Lightweight version: just `grep` for usages. Advanced version: build a simple call graph.

### 9. "Opinions" Mode — Opinionated AI Suggestions

A special lens that goes beyond correctness into **subjective quality**:

- "This could be simplified using Laravel's `when()` method"
- "Consider using a Form Request instead of inline validation"
- "This Livewire component has 8 public properties — consider splitting it"
- "The `match` expression would be cleaner than this if/elseif chain"

Key: these are explicitly labeled as *opinions*, not bugs. The UI should make this distinction clear (different icon, lighter color, "suggestion" badge).

### 10. AI Annotations UX

How AI findings appear in the review UI:

```
┌─ 🔒 Security Lens ──────────────────────────────┐
│ Line 42: SQL injection risk                      │
│                                                  │
│ `$query->whereRaw($userInput)` passes user       │
│ input directly to a raw query. Use parameterized │
│ binding: `whereRaw('col = ?', [$userInput])`     │
│                                                  │
│ [Dismiss]  [Add to my review]  [Ask AI to fix]   │
└──────────────────────────────────────────────────┘
```

- Visually distinct from human comments (colored border, icon per lens)
- "Add to my review" converts it to a human comment for the agent
- "Ask AI to fix" could generate a suggested diff (stretch goal)
- Dismissals are remembered per lens (reduces noise over time)

---

## Architecture Considerations

### Where Does the LLM Run?

Options:
1. **Local Ollama** — fully offline, privacy-preserving, slower/less capable
2. **Cloud API (OpenAI/Anthropic/etc.)** — better quality, requires API key, costs money
3. **User's choice** — let them configure their preferred provider

Recommendation: **User's choice with a simple config.** rfa is a local tool — respect that ethos. Support Ollama for privacy-conscious users, cloud APIs for quality-conscious users.

```yaml
# .rfa/config.yaml
ai:
  provider: anthropic  # or openai, ollama, openrouter
  model: claude-sonnet-4-20250514
  api_key_env: ANTHROPIC_API_KEY  # read from env var
```

### How to Feed Context to the LLM

Each lens call should include:
1. The unified diff for the file(s) being reviewed
2. The full file content (for cross-reference)
3. The lens-specific system prompt
4. Previous review comments (for follow-up analysis)
5. Project-level context (framework, language, conventions from `.rfa/context.md`)

### Token Budget Management

- Single-file reviews are cheap (~2-5K tokens input)
- Whole-diff reviews on large PRs can be expensive
- Strategy: review file-by-file, then do a cross-file summary pass
- Let users set a token budget or cost limit per review session

---

## What Makes rfa's Approach Unique

| Aspect | CodeRabbit / BugBot | rfa |
|---|---|---|
| **Where** | Cloud, on PRs | Local, pre-PR |
| **Who controls** | Auto-runs | Human activates lenses |
| **Audience** | Team reviewing peers | Human reviewing AI agent output |
| **Output** | Comments on PR | Annotations for human → structured feedback for agent |
| **Privacy** | Code goes to cloud | Can run fully local (Ollama) |
| **Customization** | Rules files | Per-lens YAML configs |
| **Workflow** | PR-centric | Session-centric, iterative |

The differentiator: rfa isn't trying to replace the reviewer. It's giving the reviewer **superpowers** while they review AI-generated code. The AI annotations are suggestions *to the human*, who then decides what to send back to the agent.

---

## Prioritized Roadmap Suggestion

### Phase 1 — Foundation
1. LLM provider configuration (API key, model selection)
2. Single lens: "General Review" (prove the UX)
3. AI annotations UI (inline on diff, distinct from human comments)

### Phase 2 — Core Lenses
4. Security lens
5. Laravel Quality lens
6. Performance lens
7. AI-generated change summary (top of review page)

### Phase 3 — Power Features
8. Custom user-defined lenses (YAML config)
9. File grouping/categorization
10. Livewire lens, Accessibility lens
11. Cross-file impact analysis
12. "Opinions" mode

### Phase 4 — Intelligence
13. Comment-aware follow-up reviews
14. Pattern detection ("you keep commenting about X")
15. Diff-aware static analysis integration (PHPStan/Pint inline)
16. Ollama/local LLM support

---

## Open Questions

- Should lenses run automatically when you open a review, or only on-demand?
- Should AI annotations persist across sessions like human comments do?
- How do we handle rate limiting / cost for cloud LLM providers?
- Should there be a "run all lenses" button, or force deliberate selection?
- Do we want a "confidence score" on each AI finding?
- Should dismissed findings train the AI (local feedback loop)?
- How do we handle large diffs that exceed context windows? (File-by-file? Chunking? Summarization?)
- Should the AI be able to read the *full* file, not just the diff, for better context?

---

## Sources & Competitive Research

- [CodeRabbit](https://www.coderabbit.ai/) — AI code reviews on PRs, IDE, CLI
- [CodeRabbit: Agentic Code Validation](https://www.coderabbit.ai/blog/how-coderabbits-agentic-code-validation-helps-with-code-reviews)
- [Cursor BugBot](https://cursor.com/bugbot) — 8-pass parallel review, agentic architecture
- [Cursor: Building BugBot](https://cursor.com/blog/building-bugbot) — Pipeline to agentic evolution
- [Greptile](https://www.greptile.com/benchmarks) — Full codebase knowledge graph, deepest context
- [Ellipsis](https://www.ellipsis.dev/) — Auto-fixes, not just reviews
- [State of AI Code Review 2025](https://www.devtoolsacademy.com/blog/state-of-ai-code-review-tools-2025/)
- [Best AI Code Review Tools 2026](https://dev.to/heraldofsolace/the-best-ai-code-review-tools-of-2026-2mb3)
- [AI Code Review Predictions 2026](https://www.qodo.ai/blog/5-ai-code-review-pattern-predictions-in-2026/) — Single-concern agents trend
- [Greptile Alternatives](https://www.getpanto.ai/blog/best-greptile-alternatives-6-best-ai-code-review-tools)
