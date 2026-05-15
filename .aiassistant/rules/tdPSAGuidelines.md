---
apply: always
---

# tdPSA Project Guidelines

## 1. Rule Prioritization
- **AGENTS.md takes precedence:** If there is a conflict between these guidelines and `AGENTS.md`, follow `AGENTS.md`.
- **Specialized project standards:** Follow `module-architecture.md` for module/domain structure and `ui-guidelines.md` for UI, layout, Blade, component, and styling work.

## 2. Database Migrations
- **Do not create new migration files** for changes to existing tables.
- **Modify the existing migration file** for the target table instead.
- Provide instructions to the developer to perform manual database updates in the development environment.
- **Goal:** Keep the migration directory clean for production deployment.

## 3. Language & Documentation
- **Everything must be in English:** Backend code, frontend text (UI), and all comments.
- **Translate on the fly:** If you encounter existing Norwegian text/comments while working, translate them to English.
- **Mandatory Documentation:** All code must be commented and documented so AI assistants can understand the context and purpose of the logic.

## 4. Testing & Logic Layer
- **Always create and update Laravel tests.**
- Ensure existing functionality is not broken when adding new features.
- **Service Layer:** Place business logic in dedicated `Service` classes instead of directly in Controllers or Livewire components to improve modularity and testability.

## 5. Project Structure & Modularization
- **Domain modules:** Domain code belongs in `app/Modules/{Domain}/` according to `module-architecture.md`.
- **No default Laravel domain locations:** Do not place domain controllers in `app/Http/Controllers` or domain views in `resources/views`.
- **Self-contained modules:** Ensure related controllers, views, routes, actions, queries, menus, and tests stay with the owning module.

## 6. Standardization & Decisions
- **Ask to standardize:** If you have to make a design choice, or if the user points out a recurring issue/complaint, ask if this pattern should be officially standardized in these rules.
- We will adjust rules incrementally as needs arise.

## 7. Development Workflow
- When code changes are made, update documentation/comments to reflect the new state.
- Ensure that modules are self-contained as much as possible.
