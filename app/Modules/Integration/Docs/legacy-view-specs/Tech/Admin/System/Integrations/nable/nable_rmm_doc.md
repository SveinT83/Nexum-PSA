### N-able RMM Integration

This system handles the synchronization of clients, sites, and assets between tdPSA and N-able RMM.

#### Functionality
*   **Client Synchronization**: Imports new clients from RMM or exports local clients to RMM.
*   **Site Synchronization**: Synchronizes sites for all linked clients.
*   **Asset Synchronization**:
    *   Imports servers and workstations from RMM.
    *   Links assets to clients and sites based on RMM IDs using the central `client_rmm_links` table.
    *   Creates a local placeholder site named `RMM Site {siteid}` when a device arrives before the corresponding RMM site has been imported. A later site sync can update the site name through the same RMM link.
    *   Supports multiple RMM integrations (e.g., both N-able and Tactical) for the same client.
    *   Uses `updateOrCreate` logic keyed by RMM Device ID to prevent duplicates and keep data current (updates Name, IP, OS, etc.).
    *   **Targeted Sync**: Can be triggered from a Client or Site profile to sync only relevant assets.
*   **Automatic Linking**: The system attempts to link existing clients based on name if they are not already linked via the mapping table.
*   **Real-time Updates**: Uses Livewire to provide progress visualization and detailed results (Created, Updated, Linked, Errors) during manual synchronization.

#### Automation
Synchronization runs automatically every hour if the integration is set to "Active" and the specific synchronization toggles (Clients, Sites, Assets) are enabled in the settings.

**Settings Management:**
The settings are split into two sections:
1.  **API Configuration**: Handles the Server URL and API Key.
2.  **Automation Settings**: Controls the background synchronization toggles. This prevents browser autofill from accidentally overwriting API credentials.

**System Requirements for Automation:**
1.  **Laravel Scheduler**: A cron job must run every minute on the server:
    ```bash
    * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
    ```
2.  **Queue Worker**: A worker must run in the background to process synchronization jobs:
    ```bash
    php artisan queue:work
    ```

#### Troubleshooting
*   **Connection Errors**: Verify that the API key is valid and the correct server region is selected.
*   **Unknown Status Error**: Ensure the API endpoint is reachable and returns valid XML. The system handles variations in XML structure but requires basic data integrity.
*   **Missing Assets**: Confirm the client is linked to N-able RMM and that the API returns a device ID and site ID. If the site has not been imported yet, Nexum creates a placeholder local site and links it to the RMM Site ID during asset sync.
*   **Logs**: Check `storage/logs/laravel.log` for detailed error messages during automatic synchronization.
