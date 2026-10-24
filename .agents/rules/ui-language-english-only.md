# UI Language Standard — English Only

All user-facing UI text across the entire application must be strictly in English.

## Rules

### 1. Application UI is Always English
- All labels, hints, placeholders, buttons, badges, modals, tooltips, toasts, error messages, and descriptions displayed on the web interface MUST be written in standard, clear English.
- NEVER use Marathi, Hindi, or mixed regional languages in:
  - HTML / Blade / View files (`app/Views/**`)
  - JavaScript UI rendering, alerts, modals, or toasts (`public/assets/js/**`)
  - Controller flash messages or API response messages (`message` field in JSON)
  - Validation error strings or status labels

### 2. Chat Communication vs Code / UI Distinction
- **Chat with User:** Casual Marathi-Hindi-English mix as requested by user guidelines.
- **Code, Git Commits, and UI Strings:** 100% English only.
