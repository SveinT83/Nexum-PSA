The N-able RMM integration synchronizes clients, sites, and assets between N-able RMM and Nexum PSA.

MSP Manager ticket comments can be mirrored into Nexum tickets through the Ticket API endpoint:

```text
POST /api/v1/tickets/{ticket}/external-messages
```

Use `source = msp_manager` and a stable MSP Manager comment or answer ID as `external_id`. The
endpoint is idempotent: sending the same `source` and `external_id` updates the existing
`TicketMessage` instead of creating a duplicate. Imported external messages are stored with
`author_type = external`, keep external author metadata, mark the Nexum ticket unread, and do not
trigger outbound Nexum email.

## Alert Routing

The shared `integrations:rmm-alert-sync` command polls active Tactical and N-able alert integrations
every 15 minutes and dispatches provider jobs. New and reopened failures enter RMM Alert Rules;
routine active refreshes and resolutions do not rerun routing.

Configure the provider-neutral rules under **Admin > System > Integrations > RMM Alert Rules**.
N-able severity and check type are retained when supplied by the failing-check feed. Missing or
unknown severity defaults to `warning`. Existing alerts are not routed retroactively.

Automatic alert routing requires the Laravel scheduler runner and a queue worker.

## Requirements

The integration must be active and configured with:

- N-able RMM server URL.
- API key.
- Enabled synchronization settings for the data direction that should run.

Client, Site, and Asset inventory synchronization is a separate hourly job:

```text
App\Jobs\Integrations\NAbleRmmSyncJob
```

This hourly inventory job does not replace the 15-minute alert polling described above. The server
must run both the Laravel scheduler and a queue worker for either automatic flow to process.

## Client And Site Mapping

Clients are linked through the shared `client_rmm_links` table.

Sites are also linked through `client_rmm_links` using `ClientSite::class` as the `linkable_type`.

Asset sync needs a linked client. Site sync should normally run before asset sync so local site names
match N-able RMM.

## Asset Import

Asset import fetches N-able `server` and `workstation` devices for linked clients.

Imported assets are linked through `client_rmm_links` using the RMM device ID. This prevents duplicate
asset records on later syncs.

Imported asset fields include:

- Client.
- Site.
- Name and hostname.
- Type.
- Vendor.
- Model.
- Serial number.
- MAC address.
- IP address.
- Source.
- Managed status.
- Last seen timestamp.
- RMM metadata.

## Placeholder Sites

If a device arrives with a RMM Site ID but the local Site link does not exist yet, Nexum creates a
placeholder site named:

```text
RMM Site {siteid}
```

The placeholder is linked to the RMM Site ID immediately. This allows asset import to complete even
when site sync was not run first.

A later site sync can resolve the same RMM Site ID and update the local site name from N-able RMM.

## Troubleshooting

If assets do not import:

- Confirm the N-able integration is active.
- Confirm the API key is saved and the connection test passes.
- Confirm the local Client is linked to a N-able client.
- Confirm the N-able API returns devices with both device ID and site ID.
- Confirm the queue worker is running for automatic sync.
- Check `storage/logs/laravel.log` for N-able sync errors.

The scheduled sync previously depended on the Site link already existing. Nexum now creates a
placeholder Site during asset sync when the RMM Site ID is present but not linked locally.
