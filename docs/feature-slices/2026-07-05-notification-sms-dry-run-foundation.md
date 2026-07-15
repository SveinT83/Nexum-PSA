# Feature Slice: Notification SMS Dry-Run Foundation

Parent RFC: `docs/rfc/2026-07-04-notification-sms-channel-automation.md`
Status: Approved
Date: 2026-07-05
Owner: Codex

## Scope

Build the first transactional SMS foundation without connecting a production SMS provider:

- Seed and configure an SMS notification channel with provider `dry_run`.
- Add transactional SMS templates owned by Notification.
- Add SMS send/audit log records for dry-run and blocked attempts.
- Add a `SendTransactionalSms` action for future workflow adoption.
- Add admin channel settings and manual dry-run test send.
- Use Contact phone consent through `contact_phones.sms_allowed` and block when `contacts.do_not_call` is set.
- Expose transactional SMS consent where primary Contact phone editing already exists.
- Update Notification and Contact documentation.

## Out Of Scope

- Telia, Twilio, or any production provider.
- Inbound or two-way SMS.
- Booking reminders, quote follow-up, invoice reminders, on-my-way messages, or other workflow automation.
- Marketing SMS campaigns.
- Queue retry and delivery callback handling.

## Acceptance Criteria

- Admins can enable the SMS channel in dry-run mode and configure sender name/default country code.
- Admins can run a manual test send to a Contact with an SMS-allowed phone number.
- Dry-run attempts create auditable SMS log records without external network calls.
- Disabled channel, missing phone, `sms_allowed=false`, and `do_not_call=true` block delivery and are logged.
- Contact create/update/API paths can persist primary phone SMS permission.
- Notification and Contact tests cover the new behavior.

## Verification

Run focused Notification and Contact tests on the Dev server before push.
