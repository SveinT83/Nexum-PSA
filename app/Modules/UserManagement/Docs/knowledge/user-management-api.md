# User Management API

The User Management API exposes beta-ready user lifecycle operations for trusted integrations and
future AI agents.

User deletion is not exposed. Nexum PSA manages account lifecycle through status.

## Abilities

- `users.read`
- `users.create`
- `users.update`

## Endpoints

- `GET /api/v1/users`
- `GET /api/v1/users/roles`
- `POST /api/v1/users`
- `GET /api/v1/users/{user}`
- `PUT /api/v1/users/{user}`
- `PATCH /api/v1/users/{user}`
- `POST /api/v1/users/{user}/status`
- `POST /api/v1/users/{user}/roles`
- `POST /api/v1/users/{user}/invite`

## User Status

Supported statuses:

- `PENDING_INVITE`
- `ACTIVE`
- `DISABLED`

Pending users receive invitation tokens through the existing invite queue.

## Create User

`POST /api/v1/users` creates a user with a random initial password and optional roles.

Supported role inputs:

- `role_id`
- `role_ids`

When the created user is `PENDING_INVITE`, the existing invitation action creates an invite token and
queues the invite email.

## Update User

`PATCH /api/v1/users/{user}` updates canonical profile fields:

- name
- email
- work phone
- private phone
- timezone
- working hours
- availability notes
- profile notes

Avatar upload is intentionally not included in the first API slice.

## Replace Roles

`POST /api/v1/users/{user}/roles` replaces the user's role list with `role_ids`.

## Security Boundary

The API never returns passwords, remember tokens, invite token values, two-factor secrets, or
two-factor recovery codes.
