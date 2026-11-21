# AI assistant

A chat panel available on every page (button bottom-right, or "AI assistant" in the sidebar; there is a full-screen toggle).

## What it can do
- Answer questions ("what is overdue?", "what should I do first?").
- Create, edit, complete/reopen and delete tasks, including bulk requests ("complete all my overdue tasks").
- Anything that changes data is shown as an **editable confirmation card**. Nothing is saved until you tap **Confirm**.

## How it works
```
message → AiController@chat → AssistantService
            ├─ builds a prompt: your role + permissions, today's date, stats, up to 40 of YOUR visible tasks
            ├─ Gemini tool-calling loop (max 3 rounds)
            │    read tools:    list_tasks, get_summary          (run immediately, scoped to you)
            │    propose tools: propose_create/update/set_status/delete  (become cards)
            └─ if Gemini is unavailable → OfflineAssistant (rule based), same card format

Confirm → AiController@execute → AiActions::execute
            re-validates (TaskRequest rules) + re-authorises (TaskPolicy) → TaskService (source = "ai")
```

## Safety
- The model never touches the database; it only *proposes*.
- On Confirm the action is validated and authorised again for the signed-in user, so a member cannot use the AI to delete someone else's task (covered by tests).
- Prompt context contains only tasks the user may see.
- Rate limits: 20 messages / minute, 60 actions / minute per user.
- Every AI-made change is flagged `AI` in the activity log.

## Resilience (`GeminiClient`)
Several API keys rotated automatically; a rate-limited key/model pair is paused without a network call; models are discovered from Google every 12 hours with a built-in fallback list; failures map to readable messages (missing key, rejected key, quota, network, safety). Details go to `storage/logs/ai-YYYY-MM-DD.log` (keys redacted).

## Offline mode (no key)
Commands: `add task Buy milk due tomorrow priority high tags home` · `complete #12` · `reopen #12` · `delete #12` · `show overdue|pending|completed|urgent|today` · `summary`.

## Setup
`GEMINI_API_KEYS=key1,key2` in `.env`, then `php artisan config:clear`.
