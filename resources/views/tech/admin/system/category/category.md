# tdPSA AI Rules

## Execution Control (Critical)
- Never create or modify database structure without explicit approval
- Never create migrations automatically
- Always STOP and ask before database changes

## Thinking Rules
- Do not act immediately
- Always analyze existing code before suggesting changes
- Always check if functionality already exists before creating new code

## Reusability
- Never create new models, services, or logic if similar already exists
- Prefer reuse over creation
- Extend existing logic instead of duplicating

## Documentation First
- Always check for existing .md documentation before implementing
- If a view has a corresponding .md file, follow it strictly
- Do not invent behavior if documentation exists
- Ask if documentation is missing or unclear

## Clarification
- If unsure, ask before implementing
- Do not guess system behavior

## Architecture
- System must be modular
- Avoid tight coupling between modules

## Simplicity
- Avoid overengineering
- Keep solutions simple and predictable

## Category Deletion Policy
- **Integrity First:** A category cannot be deleted if it is currently in use.
- **Usage Checks:** Before deletion, the system verifies if the category is linked to:
    - **Services:** Any service in the service catalog.
    - **Documentation Templates:** Any templates in the documentation module.
    - **Sub-categories:** Any child categories (hierarchical integrity).
- **UI Logic:** The "Delete" button is disabled in the Edit modal for categories that meet the above criteria.
- **Navigation:** Category names in the list are clickable to open the Edit modal for a cleaner user experience.
- **Soft Deletes:** If all checks pass, the category is soft-deleted to maintain audit trails.
- **User Feedback:** If a deletion is blocked, a clear error message is displayed to the administrator explaining why.

## Code Documentation

- Every function must have a clear purpose description
- Explain WHY the code exists, not WHAT it does
- Avoid commenting obvious code
- Document important decisions and edge cases

## Controller Documentation

- Every controller method must include a description block
- Must explain purpose, context, and expected behavior

## View Documentation

- Every view must reference its corresponding .md documentation
- Add a short description at the top of the file
- Do not implement UI without checking documentation

## Context Awareness

- Code must be understandable by future AI without extra explanation
- Avoid hidden logic
- Use clear naming and structure
