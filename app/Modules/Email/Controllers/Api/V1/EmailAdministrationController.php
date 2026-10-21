<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Actions\EnsureDefaultEmailTemplates;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\EmailAccountAdministration;
use App\Modules\Email\Services\EmailConfigurationAdministration;
use App\Modules\Email\Services\EmailTemplateAdministration;
use App\Modules\Email\Support\EmailApiAccess;
use App\Modules\Integration\Support\DomainApiAudit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Administration has explicit metadata/config projections; it never implies mailbox content rights. */
final class EmailAdministrationController extends Controller
{
    public function accounts(Request $request, EmailApiAccess $access, EmailAccountAdministration $accounts)
    {
        $access->actor($request);
        $data = $accounts->index($request);

        return response()->json(['data' => $data['accounts']->map(fn ($account) => $this->account($account)),
            'meta' => ['can_configure' => $data['canCreateAccounts']]]);
    }

    public function accountContext(Request $request, EmailApiAccess $access, EmailAccountAdministration $accounts)
    {
        $access->actor($request);
        $data = $accounts->create($request);

        return response()->json(['data' => ['users' => $data['users']->map(fn ($user) => $user->only(['id', 'name', 'email', 'status'])),
            'kinds' => ['personal', 'shared', 'system'], 'password_fields_write_only' => true,
            'update_semantics' => 'PUT full form; blank passwords preserve configured ciphertext',
            'activation_requires' => 'successful incoming and outgoing connection tests']]);
    }

    public function accountShow(Request $request, EmailAccount $account, EmailApiAccess $access, EmailAccountAdministration $accounts)
    {
        $access->actor($request);
        $accounts->edit($account, $request);

        return response()->json(['data' => $this->account($account, true)]);
    }

    public function accountStore(Request $request, EmailApiAccess $access, EmailAccountAdministration $accounts, DomainApiAudit $audit)
    {
        $actor = $access->actor($request);
        $account = DB::transaction(function () use ($request, $accounts, $audit, $actor) {
            $account = $accounts->store($request);
            $audit->record($actor, 'email', 'email.accounts.create', $account, [], $this->account($account));

            return $account;
        });

        return response()->json(['data' => $this->account($account, true)], 201);
    }

    public function accountUpdate(Request $request, EmailAccount $account, EmailApiAccess $access, EmailAccountAdministration $accounts, DomainApiAudit $audit)
    {
        $actor = $access->actor($request);
        $account = DB::transaction(function () use ($request, $account, $accounts, $audit, $actor) {
            $before = $this->account($account, true);
            $updated = $accounts->update($account, $request);
            $audit->record($actor, 'email', 'email.accounts.update', $updated, $before, $this->account($updated, true));

            return $updated;
        });

        return response()->json(['data' => $this->account($account, true)]);
    }

    public function accountActivate(Request $request, EmailAccount $account, EmailApiAccess $access, EmailAccountAdministration $accounts, DomainApiAudit $audit)
    {
        $actor = $access->actor($request);
        $data = $request->validate(['active' => ['required', 'boolean']]);
        $account = DB::transaction(function () use ($request, $account, $accounts, $audit, $actor, $data) {
            $before = $this->account($account);
            $updated = $accounts->setActive($account, $request, (bool) $data['active']);
            $audit->record($actor, 'email', 'email.accounts.activation', $updated, $before, $this->account($updated));

            return $updated;
        });

        return response()->json(['data' => $this->account($account)]);
    }

    public function accountTest(Request $request, EmailAccount $account, EmailApiAccess $access, EmailAccountAdministration $accounts, DomainApiAudit $audit)
    {
        $actor = $access->actor($request);
        $account = DB::transaction(function () use ($request, $account, $accounts, $audit, $actor) {
            $before = $this->account($account);
            $updated = $accounts->test($account, $request);
            $audit->record($actor, 'email', 'email.accounts.test', $updated, $before, $this->account($updated));

            return $updated;
        });

        return response()->json(['data' => $this->account($account),
            'status_url' => route('api.v1.email.admin.accounts.show', $account)], 202);
    }

    public function settings(Request $request, EmailApiAccess $access, EmailConfigurationAdministration $settings)
    {
        $access->actor($request);
        $data = app()->call([$settings, 'index'], ['request' => $request]);

        return response()->json(['data' => ['config' => $data['config'], 'health' => $data['health'],
            'retention_preview' => $data['retentionPreview'],
            'mail_ai_agents' => $data['mailAiAgents']->map(fn ($agent) => $agent->only(['id', 'name', 'is_default', 'default_domains'])),
            'mail_ai_available' => (bool) ($data['mailAiRuntimeAvailability']['available'] ?? false)]]);
    }

    public function updateSettings(Request $request, EmailApiAccess $access, EmailConfigurationAdministration $settings, DomainApiAudit $audit)
    {
        $actor = $access->actor($request);
        DB::transaction(function () use ($actor, $request, $settings, $audit): void {
            $before = CommonSetting::query()->where('type', 'emailhub')->pluck('value', 'name')->all();
            $after = $settings->update($request);
            $audit->record($actor, 'email', 'email.settings.update', null, $before, $after);
        });

        return $this->settings($request, $access, $settings);
    }

    public function templates(Request $request, EmailApiAccess $access, EmailTemplateAdministration $templates)
    {
        $access->actor($request);
        $data = app()->call([$templates, 'index'], ['request' => $request]);
        $page = $data['templates'];

        return response()->json(['data' => collect($page->items())->map(fn ($template) => $this->template($template)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    public function templateContext(Request $request, EmailApiAccess $access, EmailTemplateAdministration $templates)
    {
        $access->actor($request);
        $data = app()->call([$templates, 'create']);

        return response()->json(['data' => [
            'defaults' => $this->template($data['template']), 'scopes' => $data['scopes'],
            'layout_modes' => $data['layoutModes'], 'branding_layout' => $data['brandingLayout'],
            'sample_variables' => $data['sampleVariables'], 'preview' => $data['preview'],
        ]]);
    }

    public function templateShow(Request $request, EmailTemplate $template, EmailApiAccess $access, EmailTemplateAdministration $templates)
    {
        $access->actor($request);
        $data = app()->call([$templates, 'edit'], ['template' => $template]);

        return response()->json(['data' => $this->template($template), 'preview' => $data['preview']]);
    }

    public function templateStore(Request $request, EmailApiAccess $access, EmailTemplateAdministration $templates, DomainApiAudit $audit)
    {
        $actor = $access->actor($request);
        $template = DB::transaction(function () use ($actor, $request, $templates, $audit) {
            $template = app()->call([$templates, 'store'], ['request' => $request]);
            $audit->record($actor, 'email', 'email.templates.create', $template, [], $this->template($template));

            return $template;
        });

        return response()->json(['data' => $this->template($template)], 201);
    }

    public function templateUpdate(Request $request, EmailTemplate $template, EmailApiAccess $access, EmailTemplateAdministration $templates, DomainApiAudit $audit)
    {
        $actor = $access->actor($request);
        $updated = DB::transaction(function () use ($actor, $request, $template, $templates, $audit) {
            $before = $this->template($template);
            $updated = app()->call([$templates, 'update'], ['request' => $request, 'template' => $template]);
            $audit->record($actor, 'email', 'email.templates.update', $updated, $before, $this->template($updated));

            return $updated;
        });

        return response()->json(['data' => $this->template($updated)]);
    }

    public function templatePreview(Request $request, EmailApiAccess $access, EmailTemplateAdministration $templates)
    {
        $access->actor($request);

        return response()->json(['data' => app()->call([$templates, 'preview'], ['request' => $request])]);
    }

    public function templateDefaults(Request $request, EmailApiAccess $access, EnsureDefaultEmailTemplates $defaults)
    {
        $access->actor($request);
        $defaults->handle();

        return response()->json(['data' => ['initialized' => true]]);
    }

    private function template(EmailTemplate $template): array
    {
        return $template->only(['id', 'scope', 'key', 'name', 'subject', 'body_html', 'body_text', 'layout_mode',
            'layout_html', 'variables', 'is_default', 'is_active', 'created_at', 'updated_at']);
    }

    private function account(EmailAccount $account, bool $configuration = false): array
    {
        $fields = ['id', 'address', 'description', 'from_name', 'account_kind', 'owner_id', 'is_active',
            'is_global_default', 'defaults_for', 'ticket_ingress_enabled', 'provider_binding_version',
            'last_test_result', 'last_test_at', 'last_error_code', 'last_successful_fetch_at', 'last_successful_send_at'];
        if ($configuration) {
            $fields = array_merge($fields, ['delete_policy', 'imap_host', 'imap_port', 'imap_encryption', 'imap_username',
                'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username']);
        }
        $data = $account->only($fields);
        if ($configuration) {
            $data['passwords_configured'] = ['imap' => filled($account->getAttribute('imap_secret')),
                'smtp' => filled($account->getAttribute('smtp_secret'))];
            $data['grants'] = $account->userGrants()->get()->map(fn ($grant) => $grant->only([
                'user_id', 'can_view', 'can_organize', 'can_send', 'granted_by', 'granted_at']));
        }

        return $data;
    }
}
