# Nexum PSA

**Nexum PSA** is a modular, open, and modern **Professional Services Automation** platform built with **Laravel**, **Bootstrap**, and a clear mission — to bring structure, automation, and profitability to IT service providers without locking them into expensive ecosystems.

> **Meaning:** “Nexum” is Latin for *connection* or *contract* — reflecting how the platform ties together clients, tickets, assets, and billing into one cohesive flow.

---

## 🧭 Purpose

Nexum PSA is designed to replace heavy, complex PSA suites with a **lightweight, flexible, and modular architecture**.
It automates repetitive service operations while remaining fully transparent and self-hostable.

Built initially for **Trønder Data**, Nexum PSA will evolve into an open platform adaptable for any MSP, IT consultant, or service-based business.

---

## ⚙️ Core Principles

* **Speed & predictability:** every technician action must be traceable and fast.
* **Automation-first:** intelligent routing, workflows, and rules reduce manual handling.
* **Transparency:** full audit logs on all actions and configuration changes.
* **Isolation:** each customer instance runs in its own VM for data and security separation.
* **Extensibility:** modular components with replaceable integrations (RMM, billing, portals).
* **Bootstrap simplicity:** no bloat, just clean UI and fast UX.

---

## 🧹 Architecture Overview

**Framework:** Laravel 11 + Livewire + Alpine.js
**Frontend:** Bootstrap 5 (standardized layout and components)
**Database:** MySQL / MariaDB
**Authentication:** Laravel Breeze (customized for multi-role + tenant separation)
**Queue / Jobs:** Redis / Horizon
**Email handling:** IMAP + SMTP ingestion pipeline with rule engine
**Audit & Logging:** native database audit trail, action history, and system logs

Each module lives independently but shares a unified component library and event bus.

---

## 🧱 Core Modules

| Module              | Purpose                                                                           |
| ------------------- | --------------------------------------------------------------------------------- |
| **Tickets**         | Central work hub for all client issues — one-screen workflow, SLA, AI assist.     |
| **Email Hub**       | Global inbound/outbound email parsing, routing, and triage (with Fallback Inbox). |
| **Clients & Sites** | Structured hierarchy of customers, sites, and users.                              |
| **Billing**         | Aggregates billable items from tickets, contracts, and timebanks.                 |
| **Workflows**       | Configurable state machines controlling status, rules, and automation.            |
| **Documents**       | Fast inline documentation system (internal and customer-scoped).                  |
| **Templates**       | Shared form and document blueprints for reuse across modules.                     |
| **Integrations**    | RMM (N-Able, TacticalRMM), CTI (Telia), SMS (Twilio), and WordPress plugin.       |
| **Audit & Reports** | System-wide logging, metrics, SLA, and productivity reports.                      |

---

## 🧪 Tech Stack

* **Backend:** Laravel 11 (PHP 8.3+)
* **Frontend:** Bootstrap 5 + Livewire
* **Database:** MySQL or MariaDB
* **Queue / Cache:** Redis
* **Mail:** IMAP/SMTP with rule-driven ingestion
* **Auth:** Laravel Breeze + Spatie Permissions (multi-role)
* **PWA:** Full mobile/desktop Progressive Web App support
* **Containerization:** Docker-ready structure (per-tenant deployment)

---

## 🦖 Design Philosophy

Nexum PSA aims to be:

* **Understandable** — predictable file structure, clean controllers, simple Blade components.
* **Modular** — every module (Tickets, Billing, Email, etc.) can evolve independently.
* **Efficient** — one screen for work, minimal clicks, no reloads.
* **Scalable** — supports multiple environments with tenant-level isolation.

The UI is divided into **Top Header**, **Main Section**, and **Right-side Panel** for consistent layout.
Dynamic widgets are reusable and Bootstrap-based.

---

## 🔐 Access & Permissions

Role and permission handling use **Spatie Laravel Permission** and policy-first access control.

| Role         | Scope  | Typical Permissions                      |
| ------------ | ------ | ---------------------------------------- |
| Superadmin   | System | Full access, all modules and settings    |
| Technician   | System | Tickets, clients, documentation, reports |
| Client Owner | Client | Manage own users, sites, and tickets     |
| Site Owner   | Site   | Local admin for one site                 |
| User         | Site   | Basic ticket creation and tracking       |

---

## 🌐 Future Roadmap

* SLA and timebank automation
* Cross-tenant reports
* Advanced RMM integration
* REST and GraphQL APIs
* Full open-source community release under MIT license

---

## 🚀 Getting Started (developer)

**Requirements:**

* PHP 8.3+
* Composer 2.x
* Node.js 20+
* MySQL or MariaDB
* Redis (for queues)

**Setup:**

```bash
git clone https://github.com/yourorg/nexumpsa.git
cd nexumpsa
cp .env.example .env
composer install
npm install && npm run dev
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Access via `http://localhost:8000`

Default seed users:

* [superadmin@example.com](mailto:superadmin@example.com) / password
* [technician@example.com](mailto:technician@example.com) / password

---

## 🗳 License

**Nexum PSA** © 2025 Trønder Data
Released under the **MIT License** – free to use, modify, and self-host.
