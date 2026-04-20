---
apply: always
---

# tdPSA Project Guidelines

## 1. Rule Prioritization
- **CLAUDE.md takes precedence:** If there's a conflict between these guidelines and `CLAUDE.md`, follow `CLAUDE.md`.

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
- **Modular subfolders:** Maintain the current pattern of categorizing files into subfolders within standard Laravel directories (e.g., `app/Http/Controllers/Tech/CS/Contracts/`).
- Ensure related views and resources follow the same subfolder structure.

## 6. Standardization & Decisions
- **Ask to standardize:** If you have to make a design choice, or if the user points out a recurring issue/complaint, ask if this pattern should be officially standardized in these rules.
- We will adjust rules incrementally as needs arise.

## 7. Development Workflow
- When code changes are made, update documentation/comments to reflect the new state.
- Ensure that modules are self-contained as much as possible.

