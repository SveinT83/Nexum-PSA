# API Management – Functional UI Specification

URL: tech.system.integrations.api
Access: superadmin, api.admin
Controller: App\Modules\Integration\Controllers\Admin\ApiController
Status: In progress (Core implementation completed)
Difficulty: Medium
Estimated time: 4.0 hours (2.5 hours spent)
Last updated: 2026-04-21

---

## Progress Report (2026-04-21)

*   [x] Install L5-Swagger (OpenAPI).
*   [x] Implement V1 API Structure (Clients).
*   [x] Create API Management Controller.
*   [x] Add API Management UI (Initial version).
*   [x] Integrate Swagger UI into the admin panel.
*   [x] Asset Management Module (Core structure).
*   [ ] Implement IP Restrictions middleware.
*   [ ] Implement API Scopes (Sanctum Abilities).
*   [ ] Add Audit Logging for API requests.

---

## 1. Purpose

Provide a central interface for managing API access for integrations such as automation tools, external systems, and plugins.

The page allows administrators to:

* View all API keys
* Create new API keys with scoped permissions
* Control security (expiry + IP restriction)
* Access API documentation (Swagger)

---

## 2. Layout Structure

### Top Section

* Page title: "API & Integrations"
* Description text: "Manage API keys and external integrations"
* Primary button: "Create API Key"
* Secondary button: "Open API Documentation (Swagger)"

---

### Main Section

#### Table: API Keys

Columns:

* Name
* Key (masked, reveal on click)
* Scopes
* Permissions
* Allowed IP
* Expiry Date
* Last Used
* Status (Active / Expired / Revoked)

Row Actions:

* View
* Edit
* Revoke
* Delete

---

### Right Panel

#### Widgets

1. API Usage Info

* Last API call timestamp
* Total requests (future)

2. Security Info

* Reminder: Keys should not be shared
* Warning if key has no IP restriction

3. Quick Links

* Swagger documentation
* Integration guide (future)

---

## 3. Create API Key (Modal)

### Fields

#### General

* Name (text)

    * Example: "n8n automation", "WordPress plugin"

---

#### Scopes & Permissions

Component: Dynamic list

Each row:

* Scope (select)

    * Tickets
    * Clients
    * Assets
    * Knowledge
    * Integrations

* Permissions (multi-select)

    * Read
    * Write

Rules:

* At least one scope required
* Empty scope = no access

---

#### Security

1. Expiry

* Date picker (default: +1 year)
* Checkbox: "Never expire"

    * Disables date picker

2. IP Restriction (Required)

* Input field: Allowed IP
* Supports:

    * Single IP (e.g. 84.213.xxx.xxx)
    * Future: multiple IPs (optional extension)

Validation:

* Must be valid IP format
* Required field

---

#### Actions

* Create Key
* Cancel

---

### After Creation

* API key is shown ONCE
* Copy button
* Warning: "This key will not be shown again"

---

## 4. Edit API Key

Editable:

* Name
* Scopes
* Permissions
* Expiry
* IP restriction

Not editable:

* Actual key value

---

## 5. Revoke API Key

Action: "Revoke"

Behavior:

* Sets status = revoked
* Immediately blocks access

Confirmation required

---

## 6. Delete API Key

Behavior:

* Permanent removal

Confirmation required

---

## 7. API Authentication Model

Authentication method:

* Header: Authorization: Bearer {api_key}

Rules:

* No username/password authentication
* Each key represents an integration
* Keys are scoped and restricted

---

## 8. Backend Logic (Important)

### Permission Mapping

Scopes map to internal permissions:

* tickets.read → ticket.view
* tickets.write → ticket.create + ticket.edit

Same logic applies for other scopes

---

### Security Enforcement

Each request must validate:

1. API key exists
2. Not revoked
3. Not expired
4. IP matches allowed IP
5. Has required scope + permission

---

### Audit Logging

Each API request logs:

* api_key_id
* endpoint
* timestamp
* status (success/fail)

---

## 9. Swagger Integration

Button: "Open API Documentation"

Behavior:

* Opens Swagger UI in new view
* Requires authentication

Swagger includes:

* All API endpoints
* Request/response examples
* Authorization via API key input

---

## 10. UX Notes

* Table must support search and filtering
* Expired keys should be highlighted
* Revoked keys should be clearly marked
* Masked key format: sk_live_********
* Copy button available when key is visible

---

## 11. Future Extensions

(Not implemented now, but design must support)

* Multiple IPs per key
* Rate limiting per key
* Webhook configuration
* API usage statistics

---

## Summary

This page provides full control over API access in the system. It ensures integrations can be securely configured while maintaining strict control over permissions, access scope, and usage tracking.
