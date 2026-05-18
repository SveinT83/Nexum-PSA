# Nexum-PSA — MSP Platform Assessment

**Author:** Commander Cobra 🐍  
**Date:** 2026-05-16  
**Perspective:** MSP operator evaluating the platform for production deployment

---

## Current State Summary

Nexum-PSA is a Laravel 12 + Livewire 3 + Tailwind 4 platform with a modular architecture covering the core MSP stack:

| Module | Status | Depth |
|--------|--------|-------|
| **User Management** | ✅ Active | Roles, permissions, invites, 2FA enforcement |
| **Clients & Sites** | ✅ Active | Client/site hierarchy, client users, contact info |
| **Assets** | ✅ Active | Full CRUD, RMM sync, alert ingestion, metadata |
| **Tickets** | ✅ Active | Full lifecycle, queues, priorities, assignment rules, email inbound, time tracking |
| **Commercial** | ✅ Active | Contracts, SLAs, services, packages, costs |
| **Risk** | ✅ Active | Assessments, items, updates, approval workflow |
| **Storage** | ✅ Active | Boxes, items, vendors, purchase orders, stock movements |
| **Email** | ✅ Active | IMAP fetch, inbound rules, templates, health checks |
| **Documentation** | ✅ Active | Template management |
| **Knowledge** | ✅ Active | Knowledge base |
| **Sales** | ✅ Active | Leads, sales pipeline |
| **Integration** | ✅ Active | N-Able sync, TacticalRMM sync, BookStack client |
| **Taxonomy** | ✅ Active | Categories & tags |

**Infrastructure stack:** Fortify (auth), Sanctum (API), Spatie (permissions, activity log, health, backup), Horizon (queue dashboard), Telescope (debug), Livewire 3 (realtime UI).

The foundation is solid. The module system is clean, the data model is relational, and the integration layer with `ClientRmmLink` (polymorphic) is well-designed for multi-RMM support.

---

## Assessment: What's Missing vs. What Matters

### 🔴 Critical — Ship Before Production

#### 1. Dashboard & Stats Views
**Current gap:** No stats, no dashboards. An MSP lives and dies by metrics.

**Must-haves:**
- **Technician dashboard:** Open tickets assigned to me, SLA breaches approaching, avg response time
- **Manager dashboard:** Tickets opened/closed per day/week, avg resolution time, SLA compliance %, revenue per client
- **Client dashboard:** Their open tickets, asset health, contract status

**How to build it:** Add a `Dashboard` module with Livewire charts (or a simple chart library like Chart.js via Alpine). Data already exists — tickets have timestamps, priorities, assignments. Just need aggregation queries and a few Blade views.

**Effort:** 2-3 days for a solid first pass.

#### 2. TacticalRMM Deep Integration
**Current state:** TacticalRmmClient fetches clients, sites, agents, and checks. Assets sync from TRMM → Nexum with `ClientRmmLink`. But:

- **No linkback from ticket to TRMM asset** — when a ticket has `asset_id`, there's no way to jump to the TRMM agent or see its live checks
- **No alert-to-ticket automation** — alerts come in via `AssetAlert` but there's no workflow to auto-create tickets from critical alerts
- **No "Open in TRMM" button** — technicians need to jump to the RMM for remote actions

**Recommendations:**
- Add `trmm_agent_id` and `trmm_url` to `ClientRmmLink.metadata` or `Asset.metadata`
- Ticket detail view: "View in TRMM" link when `asset_id` is set and a TRMM link exists
- Alert rules: configurable mapping (e.g., "TRMM critical alert → create ticket in queue X")
- Sync TRMM checks into `AssetAlert` with proper fingerprinting for dedup

**Effort:** 1-2 days for linkback, 2-3 days for alert→ticket automation.

#### 3. Asset Context in Tickets
**Current state:** Tickets have `asset_id` but the ticket detail view doesn't show asset context (hostname, IP, last seen, open alerts, TRMM status).

**Fix:** On ticket show page, when `asset_id` is set, render an asset sidebar card with:
- Asset name, type, hostname, IP
- Open alert count
- Link to TRMM agent (if linked)
- Link to full asset detail

**Effort:** Half a day.

---

### 🟡 Important — Differentiators That Sell

#### 4. SSO / Keycloak Integration
**Current state:** Fortify handles local auth + 2FA. No SSO.

**Why it matters:** MSPs live in Microsoft 365. If Nexum can't auth via Entra ID, it's a non-starter for any shop over 5 people. Google Workspace is the other big one.

**Approach:** Keycloak as the identity broker (supports Entra ID, Google, LDAP, SAML, OIDC all behind one interface). Laravel Socialite for the Keycloak OIDC connection. Keycloak also gives you:
- Centralized user lifecycle (provision/deprovision)
- Password policies
- Conditional access
- LDAP federation (your long-term plan)

**Implementation:**
1. Deploy Keycloak container (or VM)
2. Add `laravel/socialite` + `socialiteproviders/keycloak`
3. Fortify already handles the flow — just add the Socialite callback
4. Map Keycloak groups → Spatie roles
5. Keep local auth as fallback

**Effort:** 3-5 days including Keycloak setup, Entra ID federation, and role mapping.

#### 5. Remote Access Integration (RustDesk / Splashtop)
**Why it matters:** "I can see the alert but I can't remote into the machine" is the #1 MSP complaint about tools that don't integrate RMM + remote access.

**RustDesk Server Pro** (the one you're already evaluating):
- Has an API for session management
- Self-hosted, $19.90/mo for the Pro features
- Can embed "Connect" links in asset views
- Web client available for browser-based access

**Splashtop:**
- More mature, better multi-monitor, faster on low bandwidth
- Has a REST API for session management
- But it's SaaS-only, no self-host option
- Per-seat pricing adds up

**Recommendation:** Start with RustDesk (self-hosted, cheap, API-friendly). Add a `RemoteAccess` integration module that:
- Stores RustDesk server config in `Integration` table
- Adds "Remote Access" button on asset detail / ticket asset card
- Opens RustDesk web client or generates a connection string
- Logs remote sessions in the activity log

**Effort:** 2-3 days for the RustDesk integration.

#### 6. BitDefender Gravity Zone Integration
**Why it matters:** AV/EDR is table stakes for MSPs. BitDefender is the #1 MSP AV choice (per channel surveys). If Nexum can show endpoint security status alongside RMM alerts, that's a killer feature.

**What to sync:**
- Endpoint list → Assets (or link to existing assets)
- Security status (protected/at-risk/infected) → Asset alerts
- Malware events → Auto-create tickets
- Compliance reports → Client dashboard

**GravityZone API** is well-documented REST. Same pattern as the TRMM integration.

**Effort:** 3-4 days for full sync + alert→ticket flow.

#### 7. Grafana Integration
**Why it matters:** MSPs need monitoring dashboards. You already run Grafana. The integration is simple but high-value:

**Two directions:**
- **Embed Grafana panels in Nexum** — iframe embed with auth proxy. Show network graphs, uptime, latency on asset/client dashboards
- **Push Nexum data to Grafana** — ticket metrics, SLA compliance, asset counts as Prometheus metrics via a new exporter

**Simpler start:** Just add "Open Grafana Dashboard" links on client/asset pages that deep-link to filtered dashboards. Later, embed panels.

**Effort:** 1 day for links, 3-4 days for embedded panels with auth proxy.

---

### 🟢 Nice-to-Have — Polish & Scale

#### 8. LDAP Login (Long-Term)
**Recommendation:** Skip building your own LDAP auth. Use Keycloak as the LDAP federation layer. Keycloak connects to AD/LDAP once, and Nexum talks OIDC to Keycloak. This gives you LDAP + Entra ID + Google all from one integration, and you never touch LDAP schema code in Laravel.

#### 9. Notification Framework
**Current state:** Email via Mailable for invites. No in-app notifications, no push, no SMS.

**Needed:**
- In-app notification bell (Livewire real-time)
- Configurable per-user: email, in-app, SMS (via Twilio/Vonage)
- Notification templates (admin-editable)
- Notification rules: "When ticket assigned to me → notify via X"

**Effort:** 5-7 days for a proper framework.

#### 10. Reporting Engine
**Current gap:** No reports. MSPs need:
- Monthly client reports (tickets opened/closed, SLA compliance, time spent)
- Asset reports (patch status, AV compliance, disk usage)
- Financial reports (revenue by client, contract profitability)

**Approach:** Start with PDF exports of dashboard data (dompdf), then add scheduled email delivery. Later, a full report builder.

**Effort:** 3-5 days for initial reports.

#### 11. Audit Log Visualization
**Current state:** `spatie/laravel-activitylog` is installed but I didn't see a UI for it.

**Fix:** Add a simple audit log viewer in admin — filter by user, model, action, date range. This is compliance gold for MSPs.

**Effort:** 1 day.

#### 12. API First
**Current state:** One API controller (`AssetController\Api\V1`). No API documentation, no versioning strategy.

**Why it matters:** MSPs automate everything. If Nexum doesn't have a clean API, they'll write scripts that scrape the UI. That's brittle and creates support burden.

**Approach:**
- Add `laravel/horizon` + `l5-swagger` (already in composer.json!)
- Build API resources for: Tickets, Assets, Clients, Contracts, Alerts
- Token auth via Sanctum (already installed)
- Rate limiting per integration key

**Effort:** 5-7 days for core CRUD API with docs.

---

## Priority Matrix

| # | Feature | Impact | Effort | Priority |
|---|---------|--------|-------|----------|
| 1 | Dashboard & Stats | 🔴 Critical | 2-3d | **P0** |
| 2 | TRMM Deep Integration (linkback) | 🔴 Critical | 1-2d | **P0** |
| 3 | Asset Context in Tickets | 🔴 Critical | 0.5d | **P0** |
| 4 | SSO / Keycloak | 🟡 High | 3-5d | **P1** |
| 5 | RustDesk Integration | 🟡 High | 2-3d | **P1** |
| 6 | BitDefender Integration | 🟡 High | 3-4d | **P1** |
| 7 | Alert → Ticket Automation | 🟡 High | 2-3d | **P1** |
| 8 | Grafana Links | 🟢 Medium | 1d | **P2** |
| 9 | Notification Framework | 🟢 Medium | 5-7d | **P2** |
| 10 | Reporting Engine | 🟢 Medium | 3-5d | **P2** |
| 11 | Audit Log UI | 🟢 Low | 1d | **P3** |
| 12 | Full REST API | 🟢 Medium | 5-7d | **P3** |
| 13 | LDAP (via Keycloak) | 🟢 Low | 0d | **P3** (free with #4) |

---

## Architecture Notes

### Integration Pattern (already solid)
The `Integration` + `ClientRmmLink` polymorphic pattern is good. Every new integration should follow this:
1. `Integration` record stores credentials + config
2. `ClientRmmLink` (or generalize to `ExternalLink`) maps external IDs to internal models
3. Sync jobs run via Horizon queues
4. Health checks via `is_healthy` + `last_error`

### What I'd Generalize
- Rename `ClientRmmLink` → `ExternalLink` — it's already polymorphic, and not all integrations are RMM (BitDefender isn't, Grafana isn't)
- Add an `IntegrationType` enum/registry so the UI can dynamically render settings per type
- Consider an `IntegrationSyncJob` base class with common logging, error handling, and deduplication

### Data Model Additions Needed
```
- notification_channels (user_id, channel, config)
- notification_rules (user_id, event, channels)
- dashboard_widgets (user_id, type, config, position)
- scheduled_reports (client_id, frequency, template, recipients)
- external_links → rename from client_rmm_links
- asset_software (asset_id, name, version, publisher, installed_at) ← for AV/patch sync
```

---

## Competitive Positioning

Compared to what MSPs pay for today:

| Feature | N-Able | HaloPSA | Nexum-PSA |
|---------|--------|---------|-----------|
| Monthly cost (20 techs) | ~20,000 NOK | ~8,000 NOK | **~220 NOK** (self-hosted) |
| Ticketing | ✅ | ✅ | ✅ |
| RMM Integration | Native | API | ✅ TRMM, N-Able |
| Remote Access | Built-in | Integration | **RustDesk (pending)** |
| SSO/Entra ID | ✅ | ✅ | **Keycloak (pending)** |
| 2FA | ✅ | ✅ | **✅ (just built!)** |
| AV Integration | Built-in | Integration | **BitDefender (pending)** |
| User Invites | ✅ | ✅ | **✅ (just built!)** |
| Self-hosted | ❌ | ❌ | **✅** |
| Open Source | ❌ | ❌ | **✅** |
| Customization | Limited | API only | **Full code access** |

The killer differentiator is **self-hosted + open source + full code access** at 1% of N-Able's cost. The gap is in integrations and polish — which is exactly where this assessment focuses.

---

## Recommended Sprint Plan

### Sprint 1 (Week 1-2): Core MSP UX
- [ ] Dashboard module (technician + manager views)
- [ ] Asset context card on ticket detail
- [ ] TRMM linkback ("Open in TRMM" button)
- [ ] Alert → Ticket auto-create rules

### Sprint 2 (Week 3-4): Identity & Remote Access
- [ ] Keycloak deployment + OIDC integration
- [ ] Entra ID federation in Keycloak
- [ ] RustDesk integration module
- [ ] Role mapping (Keycloak groups → Spatie roles)

### Sprint 3 (Week 5-6): Security & Monitoring
- [ ] BitDefender GravityZone integration
- [ ] Grafana dashboard links
- [ ] Audit log viewer
- [ ] Notification framework (in-app + email)

### Sprint 4 (Week 7-8): Reports & API
- [ ] Report templates (monthly client, SLA compliance)
- [ ] REST API v1 (tickets, assets, clients, alerts)
- [ ] API documentation (Swagger)
- [ ] Scheduled report delivery via email

---

*"Build the tool you wish existed."* — This is shaping up to be exactly that.