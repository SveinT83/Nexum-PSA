Email accounts and their connection settings are managed together under **Admin > Email > Email
Accounts**. Ordinary administrators do not create a separate provider record or run a mailbox
migration before adding an account.

## Add An Email Account

Select **Add account** and enter the same details used by an ordinary mail client:

- email address and display name;
- incoming IMAP server, port, connection security, username, and password;
- outgoing SMTP server, port, connection security, username, and password;
- mailbox kind, owner, Ticket ingress, default-sender scopes, delete behavior, and mailbox access.

Select **Save and test connection**. Nexum saves the account with encrypted, write-only passwords
and queues one bounded connection check. The account remains inactive while the check runs.

The check authenticates to IMAP and SMTP independently. If both logins pass, Nexum activates the
account when **Activate after a successful test** was selected. SMTP testing authenticates without
sending a message to a recipient.

## Correct A Failed Account

Open the same account and correct its server, port, security, username, or password. Do not create a
replacement account. On edit, password fields are always blank:

- leave a password blank to keep the saved password;
- enter a password to replace it for that protocol;
- select **Save and test connection** to run both checks again.

The page shows **Testing**, a successful result, or safe incoming/outgoing failure guidance. A failed
account remains inactive and editable. Raw provider responses, resolved addresses, certificate
internals, usernames, passwords, and ciphertext are never shown in alerts or logs.

Deployment automatically promotes an older exactly verified internal binding into the same Email
account. The mailbox identity, health result and access grants remain unchanged. Historical rows
may remain as inert audit evidence, but their duplicate encrypted credentials are destroyed and
cannot be selected or used by Mail runtime.

## Supported Secure Connections

| Protocol | Port | Required security |
| --- | ---: | --- |
| IMAP | 993 | SSL/TLS |
| IMAP | 143 | STARTTLS |
| SMTP | 465 | SSL/TLS |
| SMTP | 587 | STARTTLS |

Plaintext transport, certificate bypass, self-signed certificate acceptance, and arbitrary custom
ports are unavailable. An installation may add a separately reviewed endpoint policy, but that does
not add another object to the Email account workflow. OAuth is not part of the current
password-based account form.

## Permissions And Access

An active operator needs `email.account_manage` and `email.mailbox_sync_manage` to configure and
test accounts. This authority does not grant mailbox content, attachment, raw-source, conversation,
or send access. Those remain controlled by the account owner and explicit View, Organize, and Send
grants.

## Runtime And Deployment

Connection checks use the `email` queue and serialize only the account ID, binding version, and
requested activation state. A stale job cannot activate newer saved settings. Keep the documented
database worker for `email,default` running and restart long-lived queue workers after deployment so
they load the new job class.

This change includes a one-way, fail-closed database migration. Back up the database, run
`php artisan migrate --force`, clear Laravel caches and restart queue workers. No seeding is
required. The normal scheduler runner remains required for polling, health checks, reconciliation,
and other scheduled Mail work.

Human review checklist `HR-2026-09-01-001` covers the controlled production account test and the
first real receive/send confirmation.
