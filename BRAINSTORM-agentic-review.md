# Brainstorm: Agentic Review & Human-Agent Collaboration

Analysis of rfa's current review model and proposed improvements to deepen the human-agent collaboration loop.

---

## What rfa does well today

The core loop is clean: **agent writes code -> human runs rfa -> reviews diff in browser -> exports structured JSON+MD -> pastes clipboard text to agent**. The zero-build-step architecture, ephemeral Livewire state, and dual-format export (JSON for machines, Markdown for context) are solid foundations.

## Where the collaboration model breaks down

The current flow is **one-shot and uni-directional**. The human reviews, exports, and the relationship between rfa and the agent ends. There's no concept of:

- Review iterations (agent addresses feedback, human re-reviews)
- Comment severity/categorization (blocker vs. nit vs. question)
- Agent acknowledgment (did the agent understand and act on each comment?)
- Partial approval ("these 3 files are fine, focus on these 2")

---

## Proposed Improvements

### 1. Structured Comment Taxonomy

Right now comments are free-text blobs. Adding lightweight structure would help agents prioritize and act more precisely:

- **Severity levels**: `blocker | suggestion | nit | question` — an agent should know "fix this security bug" is different from "maybe rename this variable"
- **Action type**: `fix | refactor | explain | remove | reconsider` — tells the agent *what kind* of action you expect
- **Approval per-file**: Mark individual files as `approved | needs changes | skipped` — agents can skip re-touching approved files

This doesn't need to be heavy UI. A small dropdown or chip selector next to the comment textarea would suffice. The JSON schema already has `schema_version: 1`, so extending the comment object is forward-compatible.

### 2. Review Iterations & Conversation Threading

The biggest gap: rfa treats every run as isolated. In practice, human-agent review is iterative:

```
Agent writes code
  -> Human reviews (rfa round 1)
    -> Agent addresses comments
      -> Human re-reviews (rfa round 2)
        -> ...approved
```

Improvements:

- **Review chain tracking**: Each `.rfa/` export could reference a `parent_review` hash, creating a linked list of review rounds
- **Comment resolution status**: When re-running rfa, load previous review's comments and let the user mark them as `resolved | not addressed | partially addressed`
- **Diff-of-diffs**: Show what changed *since the last review*, not just vs HEAD. This is the "interdiff" concept from Gerrit/Phabricator — hugely valuable for iterative review

### 3. Smarter Export for Agent Consumption

The current Markdown is good but could be more agent-actionable:

- **Code-reference anchors**: Instead of just "Line 42", include `src/Services/Foo.php:42` — the format agents natively understand
- **Categorized sections in MD**: Group by severity (blockers first, then suggestions, then nits) so agents tackle critical issues first
- **Suggested fix snippets**: Let the reviewer optionally write "I'd expect something like..." code blocks that agents can use as starting points
- **Wider diff context window**: `buildDiffContext()` currently includes only the exact commented lines. Expanding to a few surrounding lines helps agents understand *where* in the flow the comment applies

### 4. Agent Response Protocol

The most interesting frontier — what if rfa could also *display* agent responses?

- **Response file format**: Define `.rfa/{hash}_response.json` where the agent writes back per-comment:
  ```json
  {
    "comment_id": "c-xxx",
    "status": "addressed|wont_fix|question",
    "explanation": "...",
    "diff_ref": "line range of fix"
  }
  ```
- **Response viewer**: When rfa detects a response file, overlay the agent's explanation next to each original comment — creating a threaded conversation
- **Accept/reject per response**: The human can approve or push back on specific agent responses without writing entirely new comments

This turns rfa from a "review exporter" into a **bidirectional review interface**.

### 5. Partial Approval & Selective Staging

Currently it's all-or-nothing. Useful additions:

- **File-level verdict**: `approve | request changes | skip` per file, exported in the JSON
- **Per-session file exclusion**: Like `.rfaignore` but ephemeral, for files you don't care about this round
- **"Approve and commit" flow**: For approved files, optionally `git add` them directly from rfa, so the agent only reworks flagged files

### 6. Review Templates & Checklists

For teams/individuals with recurring review criteria:

- **`.rfa/checklist.md`**: A repo-level checklist (security, tests, naming conventions, etc.) that appears in the review UI as a sidebar checklist
- **Template comments**: Quick-insert common review phrases ("needs tests", "extract to helper", "naming: use camelCase")
- **Review rubric**: A structured template the reviewer fills out (overall quality, test coverage, architecture alignment) exported alongside inline comments

### 7. UX Improvements for Faster Reviews

- **Keyboard navigation**: `j/k` to jump between files, `c` to open comment box, `Enter` to submit, `n/p` to jump between existing comments
- **Collapse/expand files**: Especially for large diffs, let the reviewer collapse already-reviewed files
- **Comment count badges**: Per-file comment counts in the sidebar
- **Inline comment display**: Show existing comments directly in the diff gutter, not just in a list

### 8. Analytics & Review Quality Signals

- **Review stats**: Comment count per file, average comment density, time spent
- **Comment resolution rate across iterations**: Track what percentage of comments get addressed per round — signal for whether agent instructions are clear
- **Pattern detection**: If the reviewer keeps making the same comment type, surface it as a potential checklist entry or agent system-prompt addition

---

## Suggested Priority Order

| Priority | Improvement | Rationale |
|----------|------------|-----------|
| **P1** | Comment severity (`blocker/suggestion/nit/question`) | Small UI change, big impact on agent prioritization |
| **P1** | File-level approval status | Lets agents know what's done vs. needs work |
| **P2** | Review chain linking (`parent_review` in JSON) | Foundation for iteration tracking |
| **P2** | Keyboard navigation (`j/k/c/n/p`) | Power-user speed, minimal backend changes |
| **P3** | Agent response protocol (`.rfa/*_response.json`) | Enables the bidirectional loop |
| **P3** | Smarter export (code-ref anchors, wider context) | Low-effort, better agent comprehension |
| **P4** | Review templates & checklists | Team-scale feature, less urgent for solo use |
| **P4** | Analytics & pattern detection | Nice-to-have once iteration tracking exists |
