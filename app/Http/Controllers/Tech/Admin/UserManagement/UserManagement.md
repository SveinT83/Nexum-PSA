# User Management & Lifecycle – tdPSA

## Purpose

This document defines how users are created, managed, activated, and controlled within tdPSA. It expands on the existing Roles & Permissions document and introduces a complete lifecycle for users, including onboarding, status handling, and security.

---

## Core Principles

* Users are created by administrators only (no public registration)
* Users must verify ownership of their email
* Users set their own password (never system-generated)
* Access is controlled by permissions, not roles alone
* User state is controlled via a status field

---

## User Lifecycle

### 1. Creation (Admin Action)

Admin creates user with:

* Name
* Email
* Role(s)
* Optional: client/site assignment

System sets:

* status = PENDING_INVITE
* password = NULL

---

### 2. Invitation

System generates a secure token and sends email:

```
/invite/accept/{token}
```

Token rules:

* Single use
* Expiry (24 hours recommended)
* Linked to user_id

---

### 3. Activation (User Action)

User clicks link and:

* Sets password
* Confirms email

System updates:

* password = hashed value
* email_verified_at = now()
* status = ACTIVE
* invite token marked as used

---

### 4. Active Usage

User can:

* Log in
* Access system based on permissions

---

### 5. Deactivation

Admin can set:

```
status = DISABLED
```

Effects:

* Login blocked
* Data retained
* Audit preserved

---

## User Status Model

Field: `status`

Values:

* PENDING_INVITE → User created, not activated
* ACTIVE → Fully usable account
* DISABLED → Access blocked

---

## Authentication Rules

User can log in ONLY if:

* status = ACTIVE
* email_verified_at is not null

Example check:

```php
if (!$user->isActive()) {
    abort(403);
}
```

---

## Database Structure

### users table (relevant fields)

* id
* name
* email
* password (nullable)
* email_verified_at
* status

---

### user_invitations table

* id
* user_id
* token
* expires_at
* used_at

---

## Security Requirements

* Passwords are never stored in plain text
* Tokens must expire
* Tokens must be single-use
* Email verification required
* All actions logged (audit)

---

## Roles & Permissions Integration

* Roles are assigned at creation
* Permissions control access
* Role assignment can happen before activation

Example:

```php
$user->assignRole('technician');
```

---

## UI Behavior

### Create User

* Form: name, email, roles
* Submit → creates user + sends invite

---

### User List

Show:

* Name
* Email
* Status
* Roles
* Last login

---

### Status Indicators

* PENDING_INVITE → "Invited"
* ACTIVE → "Active"
* DISABLED → "Disabled"

---

## Error Handling

### Invalid Token

* Show error page

### Expired Token

* Offer resend invite

### Used Token

* Block reuse

---

## Best Practices

* Never rely on roles alone for logic
* Always check permissions
* Always validate status before login
* Keep onboarding simple

---

## Future Extensions

* Client/site scoped roles
* MFA enforcement per role
* SSO integration
* User activity tracking

---

## Summary

User management in tdPSA is based on a controlled invitation flow, strong permission-based access control, and a clear lifecycle model. This ensures security, scalability, and predictable behavior across the system.
