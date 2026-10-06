# Credits revival — meter every MCP tool call, surface balance in the app

Full plan: `~/.claude/plans/zippy-petting-lightning.md`. TDD per `backend/CLAUDE.md` (failing test first).

## Decisions
- Per-tier monthly credits live in `config/credits.php` (env-overridable). No `plans` column, no seeder change.
- Tool costs tiered: read 1 / write 5 / heavy 10-25, per-tool env overrides.
- Hard block at zero for MCP; balance never goes negative for MCP charges.
- Stripe top-up packs: out of scope.

## Backend
- [x] A. Per-tier `getSubscriptionCredits()` + `subscription_credits.plans` config
- [x] B. Resolve attached plan by Stripe price (`ensureSubscriptionAttached`, `UserPlanController::subscribe`)
- [x] C. `credits` log channel + `CREDITS_LOG_ENABLED`
- [x] D. `credits.mcp` cost config, `CreditService::mcpToolCost()`, `ViewsMaxTool::creditCost()`
- [x] E. Charge in `SafeCallTool` (pre-check, charge on success, log); deposits in the 5 MCP test files
- [x] F. `mcp_tool_invocations.credits_charged` migration + `McpAuditLog` + `McpActivityController`
- [x] G. Cost in tool descriptions (`tools/list`), `/api/ai` discovery, server instructions
- [x] H. `monthly_credits` appended to `Plan`

## Frontend
- [x] I. `PlanTier.monthly_credits`, `McpActivityItem.credits_charged`, header balance badge
- [x] J. `formatMonthlyCredits()` + credits line on Plans, PlanSelector, TrialCheckout, Checkout, landing
- [x] K. `AiActivityLog` shows credits charged
- [x] L. Delete `credits-config.ts`, `useApiWithCredits.ts`

## Housekeeping
- [x] M. `backend/CLAUDE.md` domain note; review section below

## Review (2026-10-05)

**What shipped**
- Every MCP `tools/call` is metered in `SafeCallTool`: cost from `config/credits.php` `mcp`
  (read 1 / write 5 / heavy tools 10–25, env-overridable), refused with a tool error when
  balance < cost, charged via `withdraw()` only on a non-error result, logged to the `credits`
  channel, recorded as `mcp_tool_invocations.credits_charged`.
- Agents see the price: description suffix "Costs N credits per call." in `tools/list` and
  `/api/ai` (plus a `credits` block), and the server instructions explain metering.
- Per-tier monthly credits in `subscription_credits.plans` (free 0 / starter 1000 / creator 2500 /
  pro 5000 / agency 10000); `Plan` appends `monthly_credits`. Plan attachment now matches the
  Stripe price (was always the default tier) so a Pro purchase gets Pro credits.
- Frontend: header credits badge restored (links to billing), "N credits/mo" on Plans, onboarding
  picker, trial checkout, checkout and landing cards; AI activity log shows the charge per call.
- Dead code removed: `credits-config.ts`, `useApiWithCredits.ts`.

**Verification**
- Backend: `php artisan test` → same 24 env-only failures as the pre-change baseline, 0 new.
  Affected suites (Mcp*, AiDiscovery, PlanLimits, StripeWebhook, SubscriptionLifecycle): 153 pass.
- Frontend: `vitest run` 138/138; tsc + eslint errors are all pre-existing in untouched files.

**Deferred / flagged**
- Stripe top-up packs; refunding credits when an async job fails later.
- REST-side quirks found during research: thumbnails likely double-charged, `/image-generation`
  and script generate lack `check.credits`, `deductCredits` uses `forceWithdraw`.
- `.env.example` not updated (file is deny-listed): new env keys are `CREDITS_SUBSCRIPTION_{FREE,
  STARTER,CREATOR,PRO,AGENCY}`, `CREDITS_MCP_READ_DEFAULT`, `CREDITS_MCP_WRITE_DEFAULT`,
  `CREDITS_MCP_{CREATE_POST,UPLOAD_MEDIA,SEARCH_OUTLIERS,FETCH_OUTLIER,GENERATE_OUTLIER_BREAKDOWN,
  ADD_OUTLIER_CHANNEL}`, `CREDITS_LOG_ENABLED`.
