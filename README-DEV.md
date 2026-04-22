# Nexum PSA Development Environment

## Setup Complete

This development environment has been configured for the Nexum PSA project.

### Repository Structure

```
nexum-dev/
├── app/
│   ├── Console/Commands/          # Artisan commands
│   │   └── Integrations/
│   │       └── NAbleRmmSyncCommand.php    # N-Able RMM sync command
│   ├── Http/Controllers/          # Web controllers
│   ├── Jobs/                      # Background jobs
│   │   └── Integrations/
│   │       └── NAbleRmmSyncJob.php        # Sync job for queues
│   ├── Livewire/                  # Livewire components
│   ├── Models/
│   │   └── System/Integrations/
│   │       └── Integration.php            # Integration model
│   └── Services/Integrations/
│       └── NAbleRmm/
│           └── NAbleRmmClient.php         # Main API client (our focus)
├── config/                        # Laravel configuration
├── database/
│   └── migrations/                # Database migrations
├── resources/
│   └── views/                     # Blade templates
├── routes/                        # Route definitions
├── storage/                       # Logs, cache, uploads
└── tests/                         # PHPUnit tests
```

### Git Remotes

| Remote | URL | Purpose |
|--------|-----|---------|
| `gitea` | https://gitea.ramforth.net/ramforth/Nexum-PSA.git | Primary development (your Gitea) |
| `github` | https://github.com/SveinT83/Nexum-PSA.git | Upstream source |

### Branch Workflow

```bash
# Keep main in sync with Gitea
git checkout main
git pull gitea main

# Create feature branches from main
git checkout -b feature/nable-warroom-bridge

# Work on your changes...
git add .
git commit -m "feat: Add N-Able RMM bridge endpoints for War Room"

# Push to Gitea (your development server)
git push gitea feature/nable-warroom-bridge

# Create merge request in Gitea UI when ready
```

### Key Files for War Room Integration

1. **NAbleRmmClient.php** (`app/Services/Integrations/NAbleRmm/`)
   - Already implements XML API calls
   - Methods: `listClients()`, `listSites()`, `listDevices()`, `addClient()`, `addSite()`
   - Pattern to copy for War Room collector

2. **Integration Model** (`app/Models/System/Integrations/Integration.php`)
   - Stores credentials with encryption
   - `type='rmm'` field identifies N-Able integration

3. **Sync Command** (`app/Console/Commands/Integrations/NAbleRmmSyncCommand.php`)
   - Artisan command: `php artisan integrations:nable-rmm:sync`
   - Can be scheduled via cron

### Development Commands

```bash
# Install dependencies
composer install
npm install

# Environment setup
cp .env.example .env
php artisan key:generate

# Database migrations
php artisan migrate

# Seed with test data
php artisan db:seed

# Start development server
php artisan serve

# Run tests
php artisan test

# Queue worker (for sync jobs)
php artisan queue:work
```

### War Room Integration Strategy

We need to extend this codebase with API endpoints that War Room can call:

1. **New API Controller** (`app/Http/Controllers/Api/WarRoom/`)
   - `ClientsController::index()` - List all clients
   - `ClientsController::show($id)` - Single client details
   - `AssetsController::sync()` - Asset sync endpoint
   - `AlertsController::createTicket()` - Alert → ticket conversion

2. **New Routes** (`routes/api.php`)
   - `/api/warroom/clients`
   - `/api/warroom/clients/{id}/assets`
   - `/api/warroom/alerts/{id}/ticket`

3. **Reuses Existing**
   - `NAbleRmmClient` for N-Able API calls
   - `Integration` model for credentials
   - Same credential encryption/decryption

### N-Able RMM API Endpoints Used

The client already implements:
- `list_clients` - Get all clients with device counts
- `list_sites` - Get sites for a client
- `list_devices_at_client` - Get servers/workstations
- `add_client` - Create client in RMM
- `add_site` - Create site in RMM

### Environment Variables

Key `.env` variables for integrations:

```env
# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=nexum_psa
DB_USERNAME=root
DB_PASSWORD=secret

# Queue (for sync jobs)
QUEUE_CONNECTION=database

# Cache
CACHE_DRIVER=file
```

### IDE Setup

**Recommended VSCode extensions:**
- PHP Intelephense
- Laravel Extension Pack
- Blade
- GitLens

**PHPStorm users:**
- Laravel plugin enabled
- `.env` file recognition

### Testing N-Able Connection

```php
// Test via tinker
php artisan tinker

>>> use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
>>> $client = new NAbleRmmClient();
>>> $client->setCredentials('https://rmm.example.com', 'YOUR_API_KEY');
>>> $client->testConnection();
>>> $client->listClients();
```

### Documentation

- [N-Able RMM API Reference](http://192.168.10.46/books/tacticalrmm/page/n-able-n-sight-rmm-api-reference) (BookStack)
- Laravel docs: https://laravel.com/docs/11.x
- Livewire docs: https://livewire.laravel.com/docs

### Security Notes

- API keys are encrypted in database (AES-256)
- Never commit `.env` files
- Use `php artisan config:cache` in production

---

*Setup by Marty on 2026-04-22*
