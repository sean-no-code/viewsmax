# Lessons (Claude)

- **Logging is part of the plan, not a follow-up.** The Automations plan was approved only after adding a dedicated log channel, a separate rotated file, and an env off-switch. Every new backend feature area: mirror `config/logging.php` `mail` / `automations` and add a `<Feature>Log` wrapper with `context()` + `redact()` before asking for plan approval.
- **Permission prompts on read-only commands → extend `.claude/settings.json` allow list** (prefix rules like `Bash(sed -n *)`), don't just retry.
- **PHPUnit test helpers must not shadow framework methods** (`run()`, `post()`, `get()`): use `makeRun()`, `hook()` etc. Cost two red runs.
- **`Auth::forgetGuards()` before switching bearer tokens inside one test** — the RequestGuard caches the first user (see BrandFlowTest).
- **Shell generators with `|` field separators break on descriptions containing `|`.** Write files with heredocs per file, or use a separator that can't appear in prose.
