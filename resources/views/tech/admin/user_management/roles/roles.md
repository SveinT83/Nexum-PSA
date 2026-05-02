# Role Management

Dette systemet bruker Spatie Laravel-permission for håndtering av roller og tillatelser.

## Redigering av Roller

Når du redigerer en rolle, kan du:
1. Endre navn på rollen.
2. Legge til eller fjerne tillatelser dynamisk ved hjelp av Livewire-komponenten.

### Oppdatering av Rollenavn
Endring av selve rollenavnet håndteres av `UserManagementController@rolesUpdate` via en standard POST-form.

### Oppdatering av Tillatelser
Tillatelser håndteres separat av Livewire-komponenten nedenfor navne-skjemaet. Disse lagres umiddelbart ved klikk.

### Livewire-komponent: RolePermissions
- **Sti:** `app/Livewire/Tech/Admin/UserManagement/Roles/RolePermissions.php`
- **View:** `resources/views/livewire/tech/admin/user_management/roles/role-permissions.blade.php`
- **Funksjonalitet:**
    - Grupperer tillatelser basert på prefiks (f.eks. `admin.*`, `user.*`).
    - Søkefunksjon for å raskt finne spesifikke tillatelser.
    - Umiddelbar oppdatering i databasen når en tillatelse toggles (ingen "Save"-knapp nødvendig for tillatelser).
    - **Merk:** Siden komponenten ligger utenfor hovedskjemaet, vil ikke "Enter" i søkefeltet trigge en uønsket innsending av rollenavnet.

### Breadcrumbs
Systemet bruker den sentrale breadcrumb-konfigurasjonen i `config/breadcrumbs.php` under nøkkelen `admin.user_management.roles.edit`.
