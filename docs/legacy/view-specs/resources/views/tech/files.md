# Overview of files in resources/views/Tech

This document provides an overview of the files and directories within `resources/views/Tech`, explaining their purpose and how they relate to each other.

## Main Structure

The `resources/views/Tech` directory is organized by feature, with each subdirectory representing a major module of the Nexum-PSA application. The `.md` files within these directories are design and specification documents that outline the functionality, UI/UX, and technical details of each feature.

### `admin`

This directory contains settings and administrative features.

-   **`Billing`**: Manages billing runs and invoicing.
    -   `billing.md`: General specification for the billing module.
    -   `index.blade.md`: UI/UX documentation for the main billing overview.
    -   `runs/show.blade.md`: Specification for viewing a single billing run.
-   **`settings`**: Contains various application settings.
    -   **`cs`**: Contract and Service settings.
        -   `cs.md`: General overview of the Contract & Service system.
    -   **`email`**: Email account and rule settings.
        -   `Email.md`: General documentation for the email module.
        -   `accounts/accounts.md`: Documentation for managing email accounts.
        -   `rules/rules.md`: Documentation for managing email rules.
    -   **`tickets`**: Ticket-related settings.
        -   `tickets.md`: General documentation for ticket settings.
        -   `rules/ticketRules.md`: Documentation for ticket automation rules.
        -   `workflow/workflows.md`: Specification for the ticket workflow system.

### `clients`

This directory is for client management.

-   `clients.md`: General documentation for the client management module, including the index and show views.

### `cs` (Contracts & Services)

This directory contains the core logic for managing contracts and services.

-   `cs - contract & services.md`: An overview of the entire Contract & Service system.
-   **`contracs`**: Manages contracts.
    -   `contracts.md`: Functional specification for the contract system.
-   **`services`**: Manages the service catalog.
    -   `services.md`: Functional specification for the services module.

### `Documentations`

This directory is for the internal and customer-facing documentation module.

-   `documentations.md`: General concept and view specification for the documentation module.
-   `index.blade.md`: Specification for the main documentation index view.
-   **`Categories`**: Manages documentation categories.
    -   `categories.md`: Specification for creating and editing documentation categories.
    -   `create.blade.md` & `edit.blade.md`: Specifications for the category creation and editing views.
-   **`Docs`**: Manages individual documentation articles.
    -   `docs.md`: General specification for single-document views.
    -   `_form.blade.md`: Specification for the shared document creation/editing form.
    -   `create.blade.md`, `edit.blade.md`, `show.blade.md`: Specifications for the document creation, editing, and viewing pages.
-   **`Templates`**: Manages documentation templates.
    -   `create.blade.md`, `edit.blade.md`, `index.blade.md`: Specifications for creating, editing, and listing documentation templates.

### `inbox`

This directory is for the email triage inbox.

-   `inbox.md`: General documentation for the inbox module.
-   `index.blade.md`: Specification for the main inbox list view.
-   `view.blade.md`: Specification for the single email view.

### `knowledge`

This directory is for the internal knowledge base.

-   `knowledge.md`: Functional specification for the knowledge base module.
-   `create.blade.md`, `edit.blade.md`, `index.blade.md`, `show.blade.md`: Specifications for creating, editing, listing, and viewing knowledge base articles.

### `reports`

This directory contains specifications for various reports.

-   `reports.md`: General documentation for the tech reports.
-   `time_entries.blade.md`: Specification for the time entries report.

### `sales`

This directory is for the sales and order management system.

-   `sales.md`: General documentation for the sales system.
-   `create.blade.md`, `index.blade.md`, `show.blade.md`: Specifications for creating, listing, and viewing sales orders.
-   **`leads`**: Manages sales leads.
    -   `leads.md`: General documentation for the leads system.
    -   `index.blade.md` & `show.blade.md`: Specifications for the leads list and detail views.

### `storage`

This directory is for the inventory and storage management system.

-   `storage.md`: General documentation for the warehouse and inventory system.
-   **`boxes`**: Manages storage boxes.
    -   `Boxes.md`: General documentation for storage boxes.
    -   `create.blade.md` & `show.blade.md`: Specifications for creating/editing and viewing storage boxes.
-   **`items`**: Manages inventory items.
    -   `Items.md`: General documentation for inventory items.
    -   `create.blade.md` & `show.blade.md`: Specifications for creating/editing and viewing inventory items.

### `tasks`

This directory is for the task management system.

-   `task.md`: General documentation for the task system.
-a   `index.blade.md` & `show.blade.md`: Specifications for the task list and detail views.
-   **`templates`**: Manages task templates.
    -   `template.md`: General specification for the task template system.
    -   `create.blade.md` & `index.blade.md`: Specifications for creating and listing task templates.
