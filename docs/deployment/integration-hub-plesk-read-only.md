# Integration Hub Plesk Read-Only Verification Runbook

Status: Automated contract complete; manual non-production verification pending
Related: GitHub #220 and `HR-2026-08-15-001`

## Safety boundary

This runbook verifies one approved non-production Plesk binding. It must not be used against a
production/customer Integration until the Level 3 human gate is complete. The adapter sends only
fixed Plesk XML API `get` packets. It has no generic request method and no provisioning, restart,
shell, file, database, mail, DNS, or certificate-issuance operation.

Plesk credentials remain encrypted on the Nexum Integration. Do not expose the Plesk endpoint or
credential through MCP-visible data, screenshots, fixtures, logs, issue comments, or audit notes.

## Preconditions

- Select one safe non-production Plesk subscription and site owned by the reviewer.
- Create a Plesk API secret key with the minimum read access needed for the fixed inspection. Store
  it through Nexum's encrypted Integration secret mechanism.
- Configure the Integration with provider type `plesk`, exact installation, owner scope, Client,
  Site, environment, HTTPS server, and active state.
- Confirm the certificate chain for the Plesk endpoint is trusted by Nexum. Redirects are refused.
- Confirm global, capability, Integration, Client, and Site controls are not disabled.
- Use a test hostname whose ownership and expected Plesk subscription/site IDs are known. Never
  infer ownership from public DNS or a provider search.

Official protocol references:

- `https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api.28709/`
- `https://docs.plesk.com/en-US/obsidian/api-rpc/about-xml-api/reference/managing-secret-keys.37121/`

## Create the explicit Nexum binding

Preview and verify the numeric Client/Site IDs, Integration UUID, environment, normalized hostname,
and provider subscription reference. Then create the binding without a provider call:

```bash
php artisan integration-hub:bind-domain test-host.example \
  <client-id> <site-id> \
  --integration=<integration-uuid> \
  --environment=test \
  --provider-reference=<subscription-id>
```

The command fails for mismatched Client/Site, Integration owner, installation, or environment. A
hostname already owned by a different binding requires `--transfer` after explicit review. Transfer
clears prior verification and records the previous IDs; it never silently changes ownership.

Immediately after binding, provider verification is `unknown`. This is expected.

## Manual read-only check

1. Issue a grant for `nexum.hosting.sites.inspect` with exactly one Client, Site, Integration, and
   the test environment.
2. Call:

   ```text
   GET /api/v1/integration-hub/hosting/sites/<site-id>/inspect
   Idempotency-Key: hr-2026-08-15-001-plesk-1
   ```

3. Capture only sanitized result fields: correlation ID, Execution ID, status/reason, account owner
   reference, subscription/site IDs and normalized states, explicitly bound aliases, certificate
   hostname match/expiry/freshness, and verification counts.
4. Confirm the returned subscription matches the explicit provider reference, the site reports the
   expected `webspace-id`, every returned alias belongs to that site, and no unbound alias is
   exposed.
5. Confirm the response contains no API key, endpoint, authorization header, document root, raw XML,
   private key, or unrelated Plesk object.
6. Repeat the same idempotency key and confirm the stored sanitized Execution result is returned
   without a second provider call.
7. Confirm the Integration health and bound-domain freshness were updated only from the verified
   observation.

An `ok` result is valid only when provider mapping and expected-hostname TLS verification pass. A
missing bound hostname, certificate problem, or unbound provider alias is `partial`; absent or
ambiguous mapping is `unknown`; timeout/rate limit/provider outage is `unavailable`; malformed
schema is `failed`.

## Prove the emergency stop

1. Disable the exact Integration control using the emergency runbook.
2. Issue a new inspection grant and call the route with a new idempotency key.
3. Confirm `emergency_control_active`, no provider call, and a denied audit event.
4. Re-enable with a separate reason only after the evidence is recorded.

## Cancellation and retries

The HTTP client uses a bounded connect timeout, total timeout, response-size limit, and one retry
only for safe connection/429/502/503/504 failures. Cancellation is checked before provider access
and again before TLS inspection. Cancellation does not claim rollback of an already completed
provider read.

Do not manually retry authentication failures, mapping mismatches, schema errors, or unknown
ownership. Correct the source configuration first. Respect `reason.retryable`.

## Human-review record

In `HR-2026-08-15-001`, record reviewer, date, environment, sanitized Integration/Site/domain IDs,
correlation and Execution IDs, expected versus observed mapping, provider-request count, emergency
stop result, and final status. Do not record secret values or raw provider payloads.
