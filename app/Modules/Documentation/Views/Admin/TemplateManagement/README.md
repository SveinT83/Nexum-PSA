## Template Hub – Functional Specification

**Date:** 2026-05-01
**Module:** Template Management (Global)
**Primary URL:** `/tech/admin/templates`
**Controller:** `App\Http\Controllers\Tech\Admin\Templates\TemplateHubController`
**Access:** `template.admin`
**Status:** In Progress
**Difficulty:** Medium
**Estimated Time:** 4.0 hours

---

## 1. Purpose

The Template Hub is the centralized entry point for all template-related systems within tdPSA.

It provides a unified administrative interface where users can navigate to different template systems, without mixing their structure or logic.

The hub itself does **not manage templates directly**. It acts as a routing layer to specialized template modules.

---

## 2. Core Principle

Templates in tdPSA are **not a single system**.

They are divided into separate domains based on purpose and structure:

* Documentation Templates (structured form schemas)
* Email Templates (HTML + subject)
* Ticket Templates (responses, automation)
* System Templates (notifications, auth, system messages)

Each type has:

* Its own database structure
* Its own controller
* Its own editor UI

The Template Hub connects them.

---

## 3. High-Level Structure

Template Hub
├── Documentation Templates
├── Email Templates
├── Ticket Templates
├── System Templates

Each item links to a dedicated module.

---

## 4. Folder Structure (IMPORTANT)

The Template Hub must be separated from actual template modules.

```id="folder-structure"
tech/admin/
  template_hub/
    index.blade.php
    README.md

  templates/
    documentation/
      index.blade.php
      create.blade.php
      edit.blade.php

    email/
      index.blade.php
      create.blade.php
      edit.blade.php

    tickets/
      index.blade.php
      create.blade.php
      edit.blade.php

    system/
      index.blade.php
```

---

## 5. Routes

| Route                                 | Description                | Permission               |
| ------------------------------------- | -------------------------- | ------------------------ |
| `/tech/admin/templates`               | Template Hub (entry point) | `template.admin`         |
| `/tech/admin/templates/documentation` | Documentation Templates    | `template.system.manage` |
| `/tech/admin/templates/email`         | Email Templates            | `template.system.manage` |
| `/tech/admin/templates/tickets`       | Ticket Templates           | `template.ticket.manage` |
| `/tech/admin/templates/system`        | System Templates           | `template.system.manage` |

---

## 6. UI Layout

### Top Section

* Page title: Templates Management
* Description: Central hub for managing all template systems

### Main Section

* Card-based layout
* Each card represents a template module

Cards:

* Documentation Templates
* Email Templates
* Ticket Templates
* System Templates

Each card includes:

* Title
* Description
* Open button

### Right Panel (optional)

* Recently updated templates
* Template counts

---

## 7. Controller Responsibility

### TemplateHubController

Responsibilities:

* Render hub view
* Provide navigation

Does NOT:

* Handle CRUD
* Process templates
* Validate content

---

## 8. Separation of Concerns

| Type          | Purpose        | Structure        |
| ------------- | -------------- | ---------------- |
| Documentation | Data input     | JSON fields      |
| Email         | Communication  | HTML + subject   |
| Ticket        | Workflow       | Text + variables |
| System        | Core messaging | Text + variables |

---

## 9. Extensibility

New template types can be added without modifying existing ones.

Example future additions:

* Contract Templates
* Report Templates
* Automation Templates

Each new module:

* Gets its own route
* Gets its own controller
* Is added as a new card in the hub

---

## 10. Permissions

* `template.admin` → Access to Template Hub
* Sub-modules use their own permissions:

    * `template.system.manage`
    * `template.ticket.manage`

---

## 11. UX Guidelines

* Keep it simple
* No editing here
* Fast navigation only
* Clear separation between template types

---

## 12. Future Improvements

* Template usage metrics
* Last modified indicators
* Template version tracking
* Global search across templates

---

## 13. Summary

The Template Hub is a navigation layer, not a template engine.

It ensures:

* Clean architecture
* Modular scalability
* Clear separation between fundamentally different template systems

This design prevents technical debt and supports long-term product growth.

