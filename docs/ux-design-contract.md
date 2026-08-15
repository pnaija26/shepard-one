# ShepardOne UX Design Contract

**Status:** Proposed baseline for product review and implementation  
**Last updated:** 12 August 2026  
**Interactive reference:** `/design/prototype`  
**Requirements source:** [Epic breakdown](epics.md)

## Purpose

This document and the interactive prototype are the visual source of truth for ShepardOne production work. They translate the epic requirements into reusable navigation, layout, component, privacy, accessibility, and responsive patterns.

The prototype is a design reference, not production application logic. Production screens should reuse its visual language and interaction patterns while connecting to authorized Laravel APIs, real validation, loading states, audit controls, and role permissions.

## Access

Run the application locally:

```bash
composer run dev
```

Then open:

- UX prototype: `http://127.0.0.1:8000/design/prototype`
- Current application root: `http://127.0.0.1:8000`

The prototype has its own named Laravel route, `design.prototype`, so the production home page can change without removing the design reference.

## Product Direction

ShepardOne is an operational church platform. Its interface should feel calm, trustworthy, efficient, and human without resembling a marketing website.

- Use dense but readable layouts for repeated administrative work.
- Keep role scope and branch context visible.
- Put pending work, exceptions, and next actions ahead of decorative content.
- Use plain language for member-facing and sensitive workflows.
- Make privacy restrictions visible without exposing protected details.
- Preserve a consistent information hierarchy between web and mobile.

## Prototype Screen Map

| Prototype screen | Primary roles | Epic coverage | Pattern demonstrated |
| --- | --- | --- | --- |
| Branch dashboard | Branch administrator | Epics 3, 4, 7, 12, 13 | KPI hierarchy, trends, exceptions, tasks, drill-down entry points |
| Member directory | Branch administrator, authorized staff | Epics 2, 14 | High-volume search, filtering, lifecycle states, branch attribution |
| Attendance check-in | Reception, service coordinator | Epic 4 | QR/manual check-in, live totals, sync status, recent activity |
| Welfare approval | Welfare officer, approver | Epics 7, 9 | Restricted workspace, masked identity, priority queue, audited approval |
| Member mobile home | Member, team member | Epics 5, 10, 11, 12 | Digital card, quick actions, schedule, assignments, bottom navigation |
| Placeholder navigation areas | Team leader, communications staff, reporting users | Epics 5, 10, 13 | Reserved information architecture for the next design pass |

## Role-Based Navigation

### Administrative web

The persistent navigation groups work by user intent:

- **Overview:** Dashboard, People, Attendance
- **Ministry:** Teams and groups, Care and welfare, Communications, Reports
- **System:** Settings and active user context

Navigation must be generated from effective permissions. Hiding a link is not authorization; every request, search, aggregate, export, and queued operation must enforce the same server-side scope.

### Member mobile

The primary mobile destinations are:

- Home
- Schedule
- Check-in action
- Inbox
- Profile

Role-specific duties appear in context on Home rather than adding permanent navigation for every possible ministry role.

## Design Tokens

The implemented tokens live in `resources/css/app.css`. New production components should consume semantic tokens rather than introduce arbitrary colors.

### Color

| Token | Value | Use |
| --- | --- | --- |
| `--forest` | `#123b2a` | Primary actions, church identity, active navigation |
| `--green` | `#21835a` | Success, positive trends, live and synchronized states |
| `--gold` | `#d59b2b` | Attention, pending work, membership-card accent |
| `--coral` | `#d8674d` | High priority, destructive or urgent states |
| `--blue` | `#417aad` | Informational and transfer states |
| `--ink` | `#17231e` | Primary text |
| `--muted` | `#68746e` | Secondary text |
| `--line` | `#dce3df` | Borders and structural separation |
| `--canvas` | `#f4f6f3` | Application background |

Do not communicate status by color alone. Pair color with text, an icon, or both.

### Typography

- **Interface:** Instrument Sans, weights 400, 500, and 600
- **Display and key figures:** Georgia serif in the proposal; confirm the final licensed brand serif before release
- **Base administrative text:** 12-14 px depending on density
- **Minimum supporting text:** 12 px in production; the prototype's smallest labels must be increased where required during component implementation
- **Letter spacing:** zero for normal text; modest positive spacing only for short uppercase labels

### Shape and elevation

- Standard radius: 6-8 px
- Avoid nested cards and decorative floating sections.
- Use elevation only to separate actionable surfaces from the canvas.
- Tables and timelines use borders rather than multiple shadows.

### Spacing

Use a 4 px base scale: 4, 8, 12, 16, 20, 24, 32, and 40 px. Form controls and touch targets may require larger fixed dimensions.

## Core Components

Production work should establish reusable Vue components for:

- Application shell and permission-aware navigation
- Branch or organization scope picker
- Page heading and action group
- Primary, secondary, text, destructive, and icon buttons
- Search field, filter controls, tabs, and pagination
- Metric and trend display
- Status badge
- Responsive data table and compact mobile list
- Timeline and activity feed
- Person avatar and identity summary
- Privacy notice and masked-field reveal control
- Empty, loading, error, offline, synchronization, and permission-denied states
- Confirmation dialog and toast or inline feedback

Lucide is the proposal's icon library. Production controls must include accessible labels or tooltips where the icon alone is not self-explanatory.

## Responsive Contract

### Wide web: 1051 px and above

- Persistent 240 px navigation
- Full labels and branch context
- Multi-column dashboards and case workspaces
- Tables remain the primary high-volume representation

### Compact web: 721-1050 px

- Navigation collapses to a 76 px icon rail
- Secondary dashboard panels may move below primary content or be omitted when redundant
- Detail workspaces become a single column
- Every icon-only control requires an accessible label and tooltip

### Mobile: 720 px and below

- Administrative navigation becomes a drawer
- Metrics use two columns where content remains legible
- Tables become horizontally scrollable only as a transitional prototype behavior; production flows should use responsive row summaries for critical mobile workflows
- Member experience uses bottom navigation and touch-optimized actions
- Minimum interactive target: 44 by 44 px
- Fixed navigation must not cover the last content item or focused form fields

## Privacy and Security UX

The epics require least-privilege access for welfare, care, prayer, identity, and financial data.

- Label restricted workspaces before protected content appears.
- Mask beneficiary identity until the user has both permission and a valid operational reason.
- Record reveal, export, approval, rejection, and sensitive-view actions in the audit log.
- Never place protected values in URLs, browser storage, client analytics, notifications, or error messages.
- Keep public directory data visually distinct from internal and restricted data.
- Use a confirmation step for consequential approvals and destructive changes.
- Do not rely on client-side hiding as an access control.

## Required Interaction States

Every production component or journey must define the applicable states below before a story is accepted:

- Initial loading and background refresh
- Empty result and no-data-yet
- Validation error with preserved input
- Network or server error with retry path
- Offline and pending synchronization
- Success confirmation
- Warning and partial completion
- Permission denied
- Session expired
- Destructive confirmation
- Restricted-value reveal

## Accessibility Baseline

Target WCAG 2.2 AA unless an approved project decision raises the target.

- Use semantic headings, landmarks, tables, lists, and form labels.
- Preserve visible keyboard focus and logical focus order.
- Make every action keyboard operable.
- Announce asynchronous status, validation, and synchronization changes.
- Provide text alternatives for charts and meaningful imagery.
- Maintain at least 4.5:1 contrast for normal text and 3:1 for large text and UI boundaries.
- Do not use placeholder text as the only label.
- Respect reduced-motion preferences.
- Test desktop zoom at 200 percent and mobile text resizing.

## Story Delivery Workflow

For every UI story:

1. Link the story to the relevant prototype screen and section of this contract.
2. Identify the role, branch scope, permission, and data classification.
3. Reuse an established component or document why a new component is required.
4. Design all applicable interaction states before implementation is considered complete.
5. Verify keyboard, screen-reader, contrast, zoom, desktop, tablet, and mobile behavior.
6. Validate that APIs, reports, exports, searches, and jobs enforce the same scope shown in the interface.
7. Capture material design decisions in this document and update the prototype when the shared pattern changes.
8. Obtain product-owner approval for changes that intentionally diverge from the baseline.

## UI Definition of Done

A production screen is ready for acceptance when:

- It matches the established information architecture and semantic tokens.
- It is usable at wide web, compact web, and the approved mobile widths.
- Loading, empty, validation, error, success, offline, and permission states are implemented where relevant.
- Keyboard navigation and visible focus are verified.
- Text and controls do not overlap or overflow at supported widths and zoom levels.
- Protected fields and aggregates are omitted or masked according to effective permissions.
- Consequential actions provide confirmation and clear completion feedback.
- Automated tests cover critical interaction and authorization paths.
- Product review has compared the result with `/design/prototype` and documented approved differences.

## Source Ownership

| Artifact | Responsibility |
| --- | --- |
| `docs/epics.md` | Product requirements and acceptance criteria |
| `docs/ux-design-contract.md` | UX principles, patterns, constraints, and delivery checklist |
| `resources/views/welcome.blade.php` | Interactive visual reference markup |
| `resources/css/app.css` | Proposal tokens and component styling |
| `resources/js/app.js` | Proposal navigation and sample interactions |
| `routes/web.php` | Stable prototype route |

When production Vue components are introduced, move shared tokens into the selected frontend architecture and keep the prototype synchronized until the relevant production screens supersede it.

## Known Prototype Boundaries

- Sample data is fictional and static.
- Buttons demonstrate intent but do not persist data.
- Placeholder sections require detailed journey design before their implementation epics begin.
- The prototype is not evidence of server-side authorization, audit logging, accessibility conformance, or API behavior.
- Final church brand assets, licensed typography, content tone, and organizational terminology require stakeholder approval.
