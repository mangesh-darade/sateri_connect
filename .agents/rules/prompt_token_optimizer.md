# Prompt & Token Optimization Rule

## 1. Zero-Friction Prompt Interpretation
- **Auto-Infer Rough Input**: When the user provides short, informal, or mixed-language instructions (Marathi/Hinglish/raw text like "he fix kr", "he column add kr", "button click vr modal dakhav"):
  - Silently identify target files from open tabs, cursor position, or `PROJECT_MAP.md`.
  - Prioritize the active file/function where the cursor is currently placed.
  - Automatically map to CI4 MVC, CSRF, security, and activity logging standards without asking clarification questions.
  - **Shorthand handling**: Interpret 2-3 word inputs (e.g. "EmailSenderModel soft delete fix", "_tab_senders action button add") directly as actionable tasks.
  - **Error handling**: When user pastes a raw error or stack trace, jump directly to root cause and fix it; do not regurgitate the error text.
  - **NEVER** restate, re-explain, or parrot back what the user asked. Execute immediately.

## 2. Ultra-Low Token Protocol (Strict)
- **Zero Chit-Chat & Zero Preamble**: No greetings, no "Sure! I will do this", no step-by-step recap unless explicitly asked.
- **No Unsolicited Explanations**: Never write code explanations, rationale, or walk-throughs unless the user explicitly requests "explain" / "kasa kela te sang".
- **Surgical Reading & Editing**:
  - Never view entire files (>100 lines) if a specific section can be targeted with `grep_search` or slice ranges (`StartLine`/`EndLine`).
  - Use `replace_file_content` for precise blocks. Never rewrite or replace full files for minor changes.
- **Concise Final Output**:
  - Respond directly in 1-3 lines: what changed + clickable file link.
  - Never dump entire functions or files in the chat response if the file edit tool already applied the change.
- **Application Logic First**:
  - Solve tasks via deterministic code, SQL queries, or existing helper functions (`APP.*`, libraries) without invoking heavy multi-step LLM reasoning.
