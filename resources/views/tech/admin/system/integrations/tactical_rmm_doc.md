### Tactical RMM Integration

This system handles the synchronization of clients, sites, and assets between tdPSA and Tactical RMM.

#### Functionality
*   **Client Synchronization**: Synchronizes clients between tdPSA and Tactical RMM.
*   **Site Synchronization**: Synchronizes sites for all linked clients.
*   **Asset Synchronization**:
    *   Imports agents (servers, workstations, laptops) from Tactical RMM.
    *   Links assets to clients and sites based on Tactical RMM IDs.
    *   Uses `updateOrCreate` logic keyed by Tactical RMM Agent ID to prevent duplicates and keep data current.
    *   **Targeted Sync**: Can be triggered from a Client or Site profile to sync only relevant assets.
*   **Automatic Linking**: The system attempts to link existing clients and sites based on their respective Tactical RMM IDs or names.
*   **Real-time Updates**: Uses Livewire to provide progress visualization and results during manual synchronization.

#### Automation
Synchronization runs automatically on a scheduled basis if the integration is set to "Active" and the specific synchronization toggles (Clients, Sites, Assets) are enabled in the settings.

**Settings Management:**
The settings are split into two sections:
1.  **API Configuration**: Handles the Tactical RMM URL and API Key.
2.  **Automation Settings**: Controls the background synchronization toggles.

**System Requirements for Automation:**
1.  **Laravel Scheduler**: Ensures synchronization jobs are dispatched at correct intervals.
2.  **Queue Worker**: Processes the synchronization jobs in the background.

#### Implementation Notes (Technical)
*   **API Base URL**: The URL for the Tactical RMM instance (e.g., `https://api.tacticalrmm.io`).
*   **API Key**: A valid API key with permissions to read clients, sites, and agents.
*   **Asset Mapping**:
    *   Tactical RMM Agents should be mapped to the `Asset` model.
    *   `hostname` -> `hostname`
    *   `description` -> `name` (or fallback to hostname)
    *   `operating_system` -> `metadata`
    *   `local_ip` -> `ip_address`
    *   `make` -> `vendor`
    *   `model` -> `model`
    *   `serial_number` -> `serial_number`
*   **Matching Logic**:
    *   Clients are matched by `tactical_rmm_id`.
    *   Sites are matched by `tactical_rmm_id` within the client.
    *   Assets are matched by `tactical_rmm_agent_id` (stored in `rmm_id` or `metadata`).

#### Troubleshooting
*   **Connection Errors**: Verify that the API URL is accessible and the API key is correct.
*   **Site Matching**: Assets will not be imported if the site in Tactical RMM is not correctly linked to a site in tdPSA.
*   **Logs**: Monitor `storage/logs/laravel.log` for synchronization errors.
