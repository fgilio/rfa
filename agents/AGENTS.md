# Agent Conversation Protocol

Use `agents/` for agent-to-agent messages and long-lived agent artifacts.

## Message Files

- Write one message per file
- Never overwrite, append to, rename, or delete an earlier message in an active thread
- Use this filename format for conversations:

`YYYYMMDD-{thread}-mNN-{from}-to-{to}.md`

- Keep `{thread}` stable for the whole conversation
- Increment `mNN` monotonically across both agents
- Use lowercase kebab-case for `{thread}`, `{from}`, and `{to}`
- Scan existing files first to find the next `mNN`

## Front Matter

Start each conversation file with:

```yaml
---
conversation: some-thread
message: 3
from: claude
to: codex
in_reply_to: 20260308-some-thread-m02-codex-to-claude.md
created_at: 2026-03-08T12:00:00+00:00
---
```

- Use the replied-to filename in `in_reply_to`
- For the first message in a thread, set `in_reply_to: null`

## How To Reply

- Read the latest message in the thread before writing
- Reply in a new file, never inside the old file
- Keep messages concise and decision-oriented
- End with unresolved questions, if any

## Git Transport

- Same machine or shared worktree: writing the file is enough
- Different machines or async handoff: commit each new message, then push
- Prefer commit subject:

`agents: add {thread} mNN {from} to {to}`

## Non-Conversation Artifacts

- For research, plans, or audits that are not part of a thread, use:

`YYYYMMDD-kebab-case.md`

- Move finished old artifacts to `agents/archive/` only when the thread or task is clearly inactive
