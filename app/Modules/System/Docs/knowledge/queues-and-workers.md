Queues and workers keep background work running without making technicians wait in the browser.

Production should run workers through Supervisor, systemd, Docker, or another process manager. Do not rely on manual terminal sessions, because a closed SSH session or reboot will stop the worker.

Recommended production worker for the current beta:

```bash
php artisan queue:work --queue=default,economy,email --sleep=3 --tries=3 --timeout=120
```

This single worker command is enough for beta. Later, if one queue becomes heavy, split it into separate process-manager programs so slow jobs do not delay normal operational work.

Current queue overview:

| Queue | Required | Used by | Notes |
| --- | --- | --- | --- |
| `default` | Yes | Scheduled jobs, normal queued jobs, AI chat cleanup, integrations, email polling, sales emails, ticket emails, RMM sync jobs, BookStack sync jobs | This is the main Laravel queue. Most jobs use this unless code explicitly chooses another queue. |
| `economy` | Yes | Economy order generation after ticket close | Ticket close dispatches `GenerateEconomyOrdersJob` to this queue. A worker that only listens to `default` will not process these jobs. |
| `email` | Optional but supported | Manual async email polling and manual async inbound rule processing | CLI commands can dispatch `email:poll --async` and `email:process-inbound-rules --async` to this queue. Keep it in the worker list so those commands work when used. |

Admin visibility:

- Go to `Admin -> System -> Queues and Workers`.
- `Pending jobs` shows rows from Laravel's `jobs` table.
- `Ready` means the job can be picked up now.
- `Delayed` means the job exists but is scheduled for later.
- `Reserved` means a worker has picked it up and is processing or has not released it yet.
- `Failed jobs` shows jobs from Laravel's `failed_jobs` table.

If a queue shows ready jobs that never disappear, check the worker command. The `--queue=` list must include that queue name.

Example Supervisor program:

```ini
[program:tdpsa-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/Projects/tdPSA/artisan queue:work --queue=default,economy,email --sleep=3 --tries=3 --timeout=120
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/Projects/tdPSA/storage/logs/worker.log
stopwaitsecs=3600
```

Example systemd service:

```ini
[Unit]
Description=tdPSA Laravel queue worker
After=network.target

[Service]
WorkingDirectory=/var/Projects/tdPSA
ExecStart=/usr/bin/php artisan queue:work --queue=default,economy,email --sleep=3 --tries=3 --timeout=120
Restart=always
RestartSec=5
User=www-data

[Install]
WantedBy=multi-user.target
```

The scheduler is separate from workers. It should run every minute:

```cron
* * * * * cd /var/Projects/tdPSA && php artisan schedule:run >> /dev/null 2>&1
```

Useful operations:

```bash
php artisan queue:restart
php artisan queue:failed
php artisan queue:retry all
php artisan queue:flush
php artisan queue:clear --queue=default
```

Use `queue:restart` after deployments. It asks long-running workers to restart gracefully after their current job.
