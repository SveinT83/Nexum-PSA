### N-able RMM Integration

This system handles the synchronization of clients and sites between tdPSA and N-able RMM.

#### Functionality
*   **Client Synchronization**: Imports new clients from RMM or exports local clients to RMM.
*   **Site Synchronization**: Synchronizes sites for all linked clients.
*   **Automatic Linking**: The system attempts to link existing clients based on name if they are not already linked via `rmm_id`.
*   **Real-time Updates**: Uses Livewire to provide progress visualization during manual synchronization.

#### Automation
Synchronization runs automatically every hour if the integration is set to "Active".

**System Requirements for Automation:**
1.  **Laravel Scheduler**: A cron job must run every minute on the server:
    ```bash
    * * * * * cd /path/to/project && php8.2 artisan schedule:run >> /dev/null 2>&1
    ```
2.  **Queue Worker**: A worker must run in the background to process synchronization jobs:
    ```bash
    php8.2 artisan queue:work
    ```

#### Troubleshooting
*   **Connection Errors**: Verify that the API key is valid and the correct server region is selected.
*   **Unique Client Numbers**: If automatic generation of client numbers fails, the client will still be created but without a number (if allowed in the database).
*   **Logs**: Check `storage/logs/laravel.log` for detailed error messages during automatic synchronization.
