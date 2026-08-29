# Handover Note — Proceeding to Story 1.6 (Manage Scoped Roles and Permissions)

Written: 2026-08-20, end of the Story 1.5 session. Read this top-to-bottom before writing any code.

## 1. Where things stand

- Stories 1.1–1.5 are implemented and verified.
- `php artisan test`: **57 passed (178 assertions)**, 0 failures.
- `npm run build`: clean (~370ms).
- Browser testing is NOT available in this environment; verification = feature tests + build only.

## 2. IMPORTANT: uncommitted work

Stories 1.4 and 1.5 were never committed (last commit f6c0acd predates them). `git status` shows ~13 modified + ~18 untracked files, including the entire movement backend (`app/Services/BranchScope.php`, `MemberMovementService.php`, models, migrations, controller), tests, and Vue UI.

**First action for a fresh session: commit this work before starting Story 1.6.** Suggested split (or one combined commit if preferred):
- Stories 1.4 + 1.5 backend/tests/migrations/models/services/routes
- Story 1.5 frontend (api/movement.js, stores/movement.js, pages/MemberMovements.vue, router, sidebar)

Do not mix Story 1.6 changes into that commit.

## 3. Environment quirks (will bite you otherwise)

- PHP is NOT on PATH. Use Herd: `export PATH="/c/Users/admin/.config/herd/bin/php84:$PATH"` then `php artisan test`.
- Terminal runs git-bash, not PowerShell — POSIX syntax only.
- The patch tool's node linter reports a bogus `Cannot find module 'C:\c\Users\admin\...'` error after editing .js files on this host. It is a Windows path-mangling false positive; trust `npm run build` instead.
- Laravel 13: `bootstrap/app.php` was already fixed to the modern callable exception-rendering registration (409 Conflict mapping for movement conflicts). Don't revert it.

## 4. Story 1.6 acceptance criteria (verbatim from docs/epics.md, "### Story 1.6")

As an HQ security administrator: configurable roles and scoped permissions so each user performs only authorized actions in the appropriate church context.

AC1 — Role CRUD with granular permission assignment:
- Permissions assignable by organization, branch, ministry, department, team, group, module, function, record type, and supported action.
- Changes validated to prevent an administrator from granting scope they do not possess.

AC2 — Effective-permission enforcement everywhere:
- Laravel policies, gates, middleware, and query scopes calculate/enforce the effective permission for users with multiple role assignments.
- Same result through web, hybrid mobile, API, export, search, and background processing paths.

AC3 — Revocation without deployment:
- Removed permissions / expired role assignments deny access on next request.
- Cached authorization context invalidated within the agreed security window.

AC4 — Last-super-admin protection (break-glass):
- A role change that would remove the last viable super-administrator path is blocked or requires an approved break-glass procedure, and the attempt is recorded.

## 5. Existing building blocks to reuse (do not reinvent)

- `app/Services/BranchScope.php` — the Story 1.4 scope engine: resolves a user's visible branch subtree (church-wide vs branch-scoped). Story 1.6 permission scoping should build on this, not duplicate it.
- `app/Models/User.php` — has `branch_id`, `roles` relation, and `isPrivileged()` (~line 90). Check how roles are currently modeled before designing the new schema; there is likely a simple role string/column today that Story 1.6 must generalize into scoped permission grants.
- `app/Http/Middleware/` — existing MFA/auth middleware chain in `bootstrap/app.php`; add any new authorization middleware there.
- Test conventions: see `tests/Feature/BranchScopeIsolationTest.php` and `tests/Feature/MemberMovementTest.php` for factory usage, privilege setup, and assertion style (assertJsonStructure + DB state checks).
- Frontend conventions (follow exactly):
  - API wrapper: thin methods over shared `resources/js/api/client.js` (`extractApiError` helper exists there) — see `api/movement.js`.
  - Store: Pinia with normalized array state, `{ data, meta }` envelope handling, per-action loading/error flags — see `stores/movement.js`.
  - Page: single-file component using the shared `Sidebar.vue`, header pattern from `OrganizationManagement.vue`/`MemberMovements.vue`, lucide icons, Tailwind tokens (bg-canvas, text-ink, border-line, bg-brand…).
  - Router: return-based navigation guards only (VUE_ROUTER_R0025 — never use the deprecated `next()` callback). Register new routes in `resources/js/router/index.js` with `meta: { requiresAuth: true }`.
  - Sidebar nav entries live in `components/Sidebar.vue` (`organizationNavigation` array).

## 6. Suggested approach for Story 1.6

1. Commit the uncommitted 1.4/1.5 work (section 2).
2. Read `docs/epics.md` from "### Story 1.6" onward; also skim FR4 and any non-functional requirements about authorization caching/security windows — AC3 references an "agreed security window" that may be defined elsewhere in the doc.
3. Inventory current role/permission code: grep for `role`, `isPrivileged`, policies, gates to see what exists (likely minimal).
4. Design schema first: roles table, permission grants scoped by org-unit type + id + module/function/action, assignment expiry timestamps (AC3), and the last-super-admin guard (AC4). Write migrations before code.
5. Test-first per project convention: a `tests/Feature/ScopedPermissionsTest.php` covering all four ACs before implementation — mirror MemberMovementTest structure.
6. Implement enforcement in one place (a service/policy layer) so web, API, export, search, and background paths share it (AC2 explicitly demands parity across paths).
7. Frontend: role/permission management page following the movement-page pattern; wire route + sidebar link.
8. Verify with `php artisan test` (full suite) + `npm run build`; update DEVELOPMENT_STATUS.md (it currently lists 1.5 as the latest story and says "Next Story: see docs/epics.md").

## 7. Known pitfalls from this session (avoid repeating)

- Eloquent pluralization vs migration table names: always set explicit `$table` when a migration creates a non-standard name (happened with `branch_association_history`).
- `chunkById` callbacks receive a Collection of models, not a Builder — type accordingly.
- Pinia state must be normalized to arrays before assignment; API responses may be `{ data, meta }` envelopes or bare arrays.
- Don't load the `laravel-13-local-env` skill for small fixes — it consumes context budget; direct file reads + Herd PHP path are enough (user preference).

## 8. Verification commands (copy-paste)

```bash
cd /c/Users/admin/shepard-one
export PATH="/c/Users/admin/.config/herd/bin/php84:$PATH"
php artisan test          # expect: all pass, currently 57 tests
npm run build             # expect: clean Vite build
```
