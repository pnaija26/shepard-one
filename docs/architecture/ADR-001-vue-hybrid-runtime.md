# ADR-001: Vue Hybrid Runtime and Native Bridge

**Status:** Accepted  
**Date:** 2026-08-31  
**Story:** 12.1 — Establish the Secure Vue Hybrid Application

## Context

Epic 12 requires an installable Android and iOS application that reuses the Laravel API and Vue UI contracts already delivered for web. The architecture spine asked the team to select and version a Vue hybrid runtime and native bridge (for example Ionic Vue with Capacitor) before shipping mobile workflows.

## Decision

1. **Runtime:** Capacitor **8.x** wraps the existing Vue 3 + Pinia + Vue Router SPA. We do **not** adopt Ionic Vue UI components for this foundation; church branding continues through the established Tailwind design tokens in `resources/css/app.css`.
2. **Native projects:** Android and iOS platforms are managed by Capacitor (`android/`, `ios/`). Supported targets follow Capacitor’s maintained OS matrix (current stable Android and iOS major versions).
3. **API:** Hybrid clients call versioned HTTPS endpoints using base URL `VITE_API_BASE_URL` and header `X-API-Version: 1` (see `config/hybrid.php`). Relative `/api` remains valid for the web SPA.
4. **Identity:** Hybrid sign-in issues a Sanctum access token plus a rotatable device refresh credential stored server-side (hashed). Clients store tokens only through the secure credential store contract — Capacitor Preferences on native (app-private storage), never in URLs, logs, or analytics payloads.
5. **Permissions:** Camera, push, QR, files, and notifications are requested only when a feature needs them, with a user-visible purpose string and a documented fallback when denied.
6. **Connectivity:** Offline-tolerant actions are declared in `config/hybrid.php`. Unsupported actions fail closed before presenting success; tolerant actions preserve input and expose pending / retry / conflict / final status.

## Consequences

- Web and hybrid share one Vue codebase; platform differences live under `resources/js/mobile/`.
- Signing keystores, provisioning profiles, and env files stay out of source control (see `.gitignore` and `.env.mobile.example`).
- Native `npx cap sync` / store builds require local Android Studio / Xcode; CI verifies web build + API device-credential tests without compiling store binaries in this story.

## Alternatives considered

| Option | Why not |
| --- | --- |
| Ionic Vue component rewrite | Would discard the UX design contract already implemented in Tailwind |
| React Native / Flutter | Duplicates UI and violates the Vue web+hybrid decision |
| Cordova | Capacitor is the maintained successor with better Vue tooling |
