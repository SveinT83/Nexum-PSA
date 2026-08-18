<?php

namespace App\Modules\Integration\Controllers\Admin;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Integration\Actions\ActivateEmailProviderCredential;
use App\Modules\Integration\Actions\ActivateLegacyEmailProviderMigrationItem;
use App\Modules\Integration\Actions\ApplyEmailProviderCutover;
use App\Modules\Integration\Actions\CreateEmailProviderConnection;
use App\Modules\Integration\Actions\PauseEmailProviderAccountRuntime;
use App\Modules\Integration\Actions\PreviewEmailProviderCutover;
use App\Modules\Integration\Actions\PreviewLegacyEmailProviderMigration;
use App\Modules\Integration\Actions\ResumeEmailProviderAccountRuntime;
use App\Modules\Integration\Actions\RevokeEmailProviderCredential;
use App\Modules\Integration\Actions\RollbackEmailProviderCutover;
use App\Modules\Integration\Actions\StageEmailProviderCredential;
use App\Modules\Integration\Actions\StageLegacyEmailProviderMigration;
use App\Modules\Integration\Actions\VerifyEmailProviderCredential;
use App\Modules\Integration\Actions\VerifyLegacyEmailProviderMigrationItem;
use App\Modules\Integration\Models\EmailProviderConnection;
use App\Modules\Integration\Models\EmailProviderCredentialVersion;
use App\Modules\Integration\Models\EmailProviderMigrationItem;
use App\Modules\Integration\Models\EmailProviderMigrationRun;
use App\Modules\Integration\Services\EmailProviderManagementAuthorization;
use App\Modules\Integration\Services\EmailProviderRuntimeFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;

final class EmailProviderController extends Controller
{
    public function __construct(
        private readonly EmailProviderManagementAuthorization $authorization,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->authorization->authorizeProvider($request->user(), true);

        return view('integration::Tech.Admin.System.Integrations.EmailProviders.index', [
            'connections' => EmailProviderConnection::query()
                ->with(['integration', 'activeCredentialVersion'])
                ->orderByDesc('created_at')
                ->get(),
            'legacyAccounts' => EmailAccount::query()
                ->where(function ($query): void {
                    $query->whereNull('provider_credential_source')
                        ->orWhere('provider_credential_source', 'legacy');
                })
                ->whereNull('provider_integration_id')
                ->orderBy('address')
                ->get(['id', 'address', 'description', 'is_active']),
            'runs' => EmailProviderMigrationRun::query()
                ->orderByDesc('id')
                ->limit(25)
                ->get(),
            'canManagePrivate' => $actor->can(EmailProviderManagementAuthorization::PRIVATE_ENDPOINT_PERMISSION),
        ]);
    }

    public function create(Request $request): View
    {
        $actor = $this->authorization->authorizeProvider($request->user(), true);

        return view('integration::Tech.Admin.System.Integrations.EmailProviders.create', [
            'canManagePrivate' => $actor->can(EmailProviderManagementAuthorization::PRIVATE_ENDPOINT_PERMISSION),
            'trustedCidrNames' => $actor->can(EmailProviderManagementAuthorization::PRIVATE_ENDPOINT_PERMISSION)
                ? array_keys((array) config('email_provider_security.trusted_private_cidrs', []))
                : [],
        ]);
    }

    public function store(Request $request, CreateEmailProviderConnection $create): RedirectResponse
    {
        $input = $request->validate($this->connectionRules());
        $connection = $create->execute($request->user(), $input + [
            'imap_auth_type' => 'password',
            'smtp_auth_type' => 'password',
        ]);

        return redirect()
            ->route('tech.admin.system.integrations.email-providers.show', $connection->getKey())
            ->with('status', 'Email provider credentials were staged locally. Run Verify before activation.');
    }

    public function show(Request $request, string $connection): View
    {
        $connection = $this->connection($request, $connection);

        return view('integration::Tech.Admin.System.Integrations.EmailProviders.show', [
            'connection' => $connection->load(['integration', 'credentialVersions' => fn ($query) => $query->orderByDesc('version')]),
            'isRuntimeReady' => app(EmailProviderRuntimeFactory::class)->databaseReady($connection->getKey()),
        ]);
    }

    public function stageCredential(
        Request $request,
        string $connection,
        StageEmailProviderCredential $stage,
    ): RedirectResponse {
        $connection = $this->connection($request, $connection);
        $credentials = $request->validate([
            'imap_secret' => ['required', 'string', 'max:4096'],
            'smtp_secret' => ['required', 'string', 'max:4096'],
        ]);
        $stage->execute($request->user(), $connection, [
            'imap_username' => '',
            'imap_secret' => $credentials['imap_secret'],
            'smtp_username' => '',
            'smtp_secret' => $credentials['smtp_secret'],
        ]);

        return back()->with('status', 'A new secret version was staged locally. Verify it explicitly before activation.');
    }

    public function verifyCredential(
        Request $request,
        string $connection,
        int $version,
        VerifyEmailProviderCredential $verify,
    ): RedirectResponse {
        $connection = $this->connection($request, $connection);
        $credential = $this->credential($connection, $version);
        $verify->execute($request->user(), $connection, $credential);

        return back()->with('status', 'The exact staged credential version was verified.');
    }

    public function activateCredential(
        Request $request,
        string $connection,
        int $version,
        ActivateEmailProviderCredential $activate,
    ): RedirectResponse {
        $connection = $this->connection($request, $connection);
        $credential = $this->credential($connection, $version);
        $activate->execute($request->user(), $connection, $credential);

        return back()->with('status', 'The exact verified credential version is now active.');
    }

    public function revokeCredential(
        Request $request,
        string $connection,
        int $version,
        RevokeEmailProviderCredential $revoke,
    ): RedirectResponse {
        $connection = $this->connection($request, $connection);
        $credential = $this->credential($connection, $version);
        $data = $request->validate([
            'reason_code' => ['required', 'regex:/^[a-z0-9_.-]{1,80}$/'],
        ]);
        $revoke->execute($request->user(), $connection, $credential, $data['reason_code']);

        return back()->with('status', 'Credential ciphertext was destroyed and the local version was revoked.');
    }

    public function previewMigration(
        Request $request,
        PreviewLegacyEmailProviderMigration $preview,
    ): RedirectResponse {
        $data = $request->validate([
            'account_ids' => ['required', 'array', 'min:1', 'max:50'],
            'account_ids.*' => ['required', 'integer', 'distinct', 'exists:email_accounts,id'],
        ]);
        $run = $preview->execute($request->user(), $data['account_ids']);

        return redirect()
            ->route('tech.admin.system.integrations.email-providers.migrations.show', $run->public_id)
            ->with('status', 'Read-only migration preview created. No DNS or provider call was made.');
    }

    public function showMigration(Request $request, string $run): View
    {
        $this->authorization->authorizeProvider($request->user(), true);
        $run = $this->run($run)->load(['items.account']);
        $canManagePrivate = $request->user()->can(EmailProviderManagementAuthorization::PRIVATE_ENDPOINT_PERMISSION);

        return view('integration::Tech.Admin.System.Integrations.EmailProviders.migration', [
            'run' => $run,
            'canManagePrivate' => $canManagePrivate,
            'trustedCidrNames' => $canManagePrivate
                ? array_keys((array) config('email_provider_security.trusted_private_cidrs', []))
                : [],
        ]);
    }

    public function stageMigration(
        Request $request,
        string $run,
        StageLegacyEmailProviderMigration $stage,
    ): RedirectResponse {
        $run = $this->run($run);
        $data = $request->validate([
            'trust' => ['nullable', 'array'],
            'trust.*.trust_mode' => ['required', 'in:public,trusted_private'],
            'trust.*.trusted_cidr_name' => ['nullable', 'string', 'max:120'],
            'trust.*.private_endpoint_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $stage->execute($request->user(), $run, $data['trust'] ?? []);

        return back()->with('status', 'Legacy credentials were fingerprinted and re-encrypted locally. No provider call was made.');
    }

    public function verifyMigrationItem(
        Request $request,
        string $run,
        int $item,
        VerifyLegacyEmailProviderMigrationItem $verify,
    ): RedirectResponse {
        $run = $this->run($run);
        $item = $this->item($run, $item);
        $verify->execute($request->user(), $item);

        return back()->with('status', 'The staged migration item was verified against its provider.');
    }

    public function activateMigrationItem(
        Request $request,
        string $run,
        int $item,
        ActivateLegacyEmailProviderMigrationItem $activate,
    ): RedirectResponse {
        $run = $this->run($run);
        $item = $this->item($run, $item);
        $activate->execute($request->user(), $item);

        return back()->with('status', 'The exact verified provider version was activated locally.');
    }

    public function pauseAccount(
        Request $request,
        string $run,
        int $item,
        PauseEmailProviderAccountRuntime $pause,
    ): RedirectResponse {
        $item = $this->item($this->run($run), $item);
        $account = EmailAccount::query()->findOrFail($item->email_account_id);
        $data = $request->validate([
            'reason_code' => ['required', 'regex:/^[a-z0-9_.-]{1,80}$/'],
        ]);
        $pause->execute($request->user(), $account, $data['reason_code']);

        return back()->with('status', 'Mailbox provider work is paused and drained.');
    }

    public function resumeAccount(
        Request $request,
        string $run,
        int $item,
        ResumeEmailProviderAccountRuntime $resume,
    ): RedirectResponse {
        $item = $this->item($this->run($run), $item);
        $account = EmailAccount::query()->findOrFail($item->email_account_id);
        $resume->execute($request->user(), $account);

        return back()->with('status', 'Mailbox provider work resumed.');
    }

    public function previewCutover(
        Request $request,
        string $run,
        PreviewEmailProviderCutover $preview,
    ): RedirectResponse {
        $run = $preview->execute($request->user(), $this->run($run));

        return redirect()
            ->route('tech.admin.system.integrations.email-providers.migrations.show', $run->public_id)
            ->with('status', 'Exact cutover readiness preview created. No binding was changed.');
    }

    public function applyCutover(
        Request $request,
        string $run,
        ApplyEmailProviderCutover $apply,
    ): RedirectResponse {
        $run = $apply->execute($request->user(), $this->run($run));

        return back()->with('status', 'Verified provider bindings were applied locally.');
    }

    public function rollbackCutover(
        Request $request,
        string $run,
        RollbackEmailProviderCutover $rollback,
    ): RedirectResponse {
        $rollbackRun = $rollback->execute($request->user(), $this->run($run));

        return redirect()
            ->route('tech.admin.system.integrations.email-providers.migrations.show', $rollbackRun->public_id)
            ->with('status', 'Legacy bindings were restored within the rollback window.');
    }

    /** @return array<string, mixed> */
    private function connectionRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'imap_host' => ['required', 'string', 'max:253'],
            'imap_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'imap_transport' => ['required', 'in:implicit_tls,starttls'],
            'imap_username' => ['required', 'string', 'max:1024'],
            'imap_secret' => ['required', 'string', 'max:4096'],
            'smtp_host' => ['required', 'string', 'max:253'],
            'smtp_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'smtp_transport' => ['required', 'in:implicit_tls,starttls'],
            'smtp_username' => ['required', 'string', 'max:1024'],
            'smtp_secret' => ['required', 'string', 'max:4096'],
            'trust_mode' => ['required', 'in:public,trusted_private'],
            'trusted_cidr_name' => ['nullable', 'string', 'max:120'],
            'private_endpoint_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function connection(Request $request, string $id): EmailProviderConnection
    {
        $this->authorization->authorizeProvider($request->user(), true);

        return EmailProviderConnection::query()->findOrFail($id);
    }

    private function credential(
        EmailProviderConnection $connection,
        int $version,
    ): EmailProviderCredentialVersion {
        return $connection->credentialVersions()->where('version', $version)->firstOrFail();
    }

    private function run(string $publicId): EmailProviderMigrationRun
    {
        return EmailProviderMigrationRun::query()->where('public_id', $publicId)->firstOrFail();
    }

    private function item(EmailProviderMigrationRun $run, int $itemId): EmailProviderMigrationItem
    {
        return $run->items()->whereKey($itemId)->firstOrFail();
    }
}
