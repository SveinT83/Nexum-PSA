### Tactical RMM Integration

This system handles the synchronization of clients, sites, and assets between tdPSA and Tactical RMM.

#### Functionality
*   **Client Synchronization**: Synchronizes clients between tdPSA and Tactical RMM.
*   **Site Synchronization**: Synchronizes sites for all linked clients.
*   **Asset Synchronization**:
    *   Imports agents (servers, workstations, laptops) from Tactical RMM.
    *   Links assets to clients and sites based on Tactical RMM IDs using the central `client_rmm_links` table.
    *   Supports multiple RMM integrations for the same client.
    *   Uses `updateOrCreate` logic keyed by Tactical RMM Agent ID to prevent duplicates and keep data current.
    *   **Targeted Sync**: Can be triggered from a Client or Site profile to sync only relevant assets.
*   **Automatic Linking**: The system attempts to link existing clients and sites based on their respective names or IDs in the mapping table.
*   **Real-time Updates**: Uses Livewire to provide progress visualization and results during manual synchronization.
*   **Immediate Execution**: Manual sync runs synchronously in the foreground for immediate results, while automated sync runs via the queue.

#### Automation
Synchronization runs automatically on a scheduled basis if the integration is set to "Active" and the specific synchronization toggles (Clients/Sites, Assets) are enabled in the settings.

**Settings Management:**
The settings are split into two sections:
1.  **API Configuration**: Handles the Tactical RMM URL and API Key.
2.  **Automation Settings**: Controls the background synchronization toggles. Note: Client and Site synchronization are controlled by a single toggle.

**System Requirements for Automation:**
1.  **Laravel Scheduler**: Ensures synchronization jobs are dispatched at correct intervals via `php artisan integrations:tactical-rmm-sync`.
2.  **Queue Worker**: Processes the synchronization jobs in the background.

#### Implementation Notes (Technical)
*   **Queue Jobs**: `SyncTacticalClientsJob` and `SyncTacticalAssetsJob` handle the heavy lifting. They can be run synchronously via `handle()` for manual sync or queued via `dispatch()`.
*   **Cache-based Progress**: Both jobs update progress in the Cache, which is polled by the Livewire component `TacticalRmmSync`.
*   **Matching Strategy**: 
    *   **Strict ID**: Uses `client_rmm_links` for all mappings.
    *   **Name-based Fallback (Import)**: During initial sync, matches by name to establish the first link.
*   **API Base URL**: The URL for the Tactical RMM instance (e.g., `https://api.tacticalrmm.io`).
*   **API Key**: A valid API key with permissions to read clients, sites, and agents.
*   **Asset Mapping**:
    *   Tactical RMM Agents are mapped to the `Asset` model.
    *   `hostname` -> `hostname`
    *   `description` -> `name` (or fallback to hostname)
    *   `operating_system` -> `metadata`
    *   `local_ip` -> `ip_address`
    *   `make` -> `vendor`
    *   `model` -> `model`
    *   `serial_number` -> `serial_number`
*   **Matching Logic**:
    *   Clients are matched by name or external ID in the `client_rmm_links` table.
    *   Sites are matched by name or external ID within the client.
    *   Assets are matched by Tactical RMM Agent ID (stored in the `client_rmm_links` table with `linkable_type` = `Asset`).
    *   **Fallback Matching**: Hvis ingen direkte ID-link finnes for en Asset, vil systemet søke etter samme **hostname** under den identifiserte **klienten**. Dette gjør at Assets fra både N-Able og Tactical RMM kan samles på samme Asset-post i tdPSA.

#### Troubleshooting
*   **Connection Errors**: Verify that the API URL is accessible and the API key is correct.
*   **Site Matching**: Assets will not be imported if the site in Tactical RMM is not correctly linked to a site in tdPSA.
*   **Duplicate Key Errors**: Usually caused by "dangling" RMM links pointing to deleted assets. The sync logic now attempts to detect and repair these.
*   **Logs**: Monitor `storage/logs/laravel.log` for synchronization errors tagged with `TacticalRmmSync`.
