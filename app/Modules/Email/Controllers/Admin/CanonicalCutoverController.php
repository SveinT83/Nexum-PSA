<?php

namespace App\Modules\Email\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Email\Actions\ApplyEmailCanonicalCutover;
use App\Modules\Email\Actions\PreviewEmailCanonicalCutover;
use App\Modules\Email\Actions\ProcessEmailCanonicalParityAttestation;
use App\Modules\Email\Actions\RollbackEmailCanonicalCutover;
use App\Modules\Email\Actions\StartEmailCanonicalParityAttestation;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailCanonicalCorrelationRun;
use App\Modules\Email\Models\EmailCanonicalCutoverRun;
use App\Modules\Email\Models\EmailCanonicalParityAttestation;
use App\Modules\Email\Models\EmailCanonicalReadMode;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Human-operated maintenance boundary for canonical placement cutover. Every POST creates a
 * durable preview or applies/rolls back one exact preview; this controller never calls a provider.
 */
class CanonicalCutoverController extends Controller
{
    private const MAX_ACCOUNT_SCOPE = 25;

    private const MAX_CANDIDATE_EDGES = 496;

    public function __construct(private readonly ResolveMailboxAccessDecision $accessDecisions) {}

    public function index(Request $request): View
    {
        $actor = $this->operator($request);
        $schemaReady = $this->schemaReady();
        $accounts = $this->viewableAccounts($actor);
        $allowedIds = $accounts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $runs = collect();
        $correlationRuns = collect();
        $attestations = collect();

        if ($schemaReady) {
            $runs = EmailCanonicalCutoverRun::query()
                ->latest('id')
                ->limit(250)
                ->get()
                ->filter(fn (EmailCanonicalCutoverRun $run): bool => $this->scopeIsAllowed(
                    $run->account_scope_json,
                    $allowedIds,
                ))
                ->take(50);
            $correlationRuns = EmailCanonicalCorrelationRun::query()
                ->where('status', EmailCanonicalCorrelationRun::STATUS_COMPLETED)
                ->latest('id')
                ->limit(250)
                ->get()
                ->filter(fn (EmailCanonicalCorrelationRun $run): bool => $this->scopeIsAllowed(
                    $run->account_scope_json,
                    $allowedIds,
                ))
                ->take(50);
            $attestations = EmailCanonicalParityAttestation::query()
                ->whereIn('email_account_id', $allowedIds)
                ->latest('id')
                ->limit(50)
                ->get();
        }

        return view('email::Admin.Cutover.index', compact(
            'accounts',
            'attestations',
            'correlationRuns',
            'runs',
            'schemaReady',
        ));
    }

    public function backfill(
        Request $request,
        PreviewEmailCanonicalCutover $preview,
    ): RedirectResponse {
        $actor = $this->operatorForMutation($request);
        $data = $this->boundedSourceScope($request);
        $run = $preview->backfill(
            $actor,
            $data['account_ids'],
            $data['min_message_id'] ?? null,
            $data['max_message_id'] ?? null,
            $data['item_cap'] ?? PreviewEmailCanonicalCutover::DEFAULT_ITEM_CAP,
        );

        return $this->previewRedirect($run, 'Self-map/backfill preview created.');
    }

    public function audit(
        Request $request,
        PreviewEmailCanonicalCutover $preview,
    ): RedirectResponse {
        $actor = $this->operatorForMutation($request);
        $data = $this->boundedSourceScope($request);
        $run = $preview->audit(
            $actor,
            $data['account_ids'],
            $data['min_message_id'] ?? null,
            $data['max_message_id'] ?? null,
            $data['item_cap'] ?? PreviewEmailCanonicalCutover::DEFAULT_ITEM_CAP,
        );

        return $this->previewRedirect($run, 'Parity/drift audit preview created.');
    }

    public function merge(
        Request $request,
        PreviewEmailCanonicalCutover $preview,
    ): RedirectResponse {
        $actor = $this->operatorForMutation($request);
        $data = $request->validate([
            'correlation_run_id' => ['required', 'integer', 'min:1'],
            'candidate_ids' => ['required', 'string', 'max:8000'],
        ]);
        $correlationRun = EmailCanonicalCorrelationRun::query()
            ->where('status', EmailCanonicalCorrelationRun::STATUS_COMPLETED)
            ->findOrFail((int) $data['correlation_run_id']);
        $this->authorizeAccountScope($correlationRun->account_scope_json, $actor);
        $candidateIds = $this->candidateIds((string) $data['candidate_ids']);
        $run = $preview->merge($actor, $correlationRun, $candidateIds);

        return $this->previewRedirect($run, 'Exact reviewed-component merge preview created.');
    }

    public function mode(
        Request $request,
        PreviewEmailCanonicalCutover $preview,
        StartEmailCanonicalParityAttestation $startAttestation,
        ProcessEmailCanonicalParityAttestation $processAttestation,
    ): RedirectResponse {
        $actor = $this->operatorForMutation($request);
        if ($request->input('intent') === 'attest') {
            $data = $request->validate([
                'account_id' => ['nullable', 'integer', 'min:1', 'required_without:attestation_id'],
                'attestation_id' => ['nullable', 'integer', 'min:1', 'required_without:account_id'],
            ]);
            $allowedIds = $this->viewableAccounts($actor)
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();
            if (! empty($data['attestation_id'])) {
                $attestation = EmailCanonicalParityAttestation::query()
                    ->whereIn('email_account_id', $allowedIds)
                    ->findOrFail((int) $data['attestation_id']);
            } else {
                $accountId = (int) $data['account_id'];
                abort_unless(in_array($accountId, $allowedIds, true), 404);
                $attestation = $startAttestation->handle($actor, $accountId, strictEvidence: true);
            }
            if ($attestation->status !== EmailCanonicalParityAttestation::STATUS_COMPLETED) {
                $attestation = $processAttestation->handle($attestation, $actor);
            }

            return redirect()
                ->route('tech.admin.settings.email.canonical-cutover.index')
                ->with(
                    'success',
                    $attestation->status === EmailCanonicalParityAttestation::STATUS_COMPLETED
                        ? 'Whole-account canonical parity attestation completed.'
                        : 'One bounded parity page was recorded. Continue until the frozen account is complete.',
                );
        }

        $data = $request->validate([
            'intent' => ['nullable', 'string', Rule::in(['mode'])],
            'account_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ACCOUNT_SCOPE],
            'account_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'mode' => ['required', 'string', Rule::in(EmailCanonicalReadMode::MODES)],
        ]);
        $run = $preview->mode($actor, $data['account_ids'], $data['mode']);

        return $this->previewRedirect($run, 'Read-mode preview created.');
    }

    public function show(Request $request, string $run): View
    {
        $actor = $this->operatorForMutation($request);
        $run = $this->runForActor($run, $actor);
        $accounts = $this->authorizeAccountScope($run->account_scope_json, $actor);
        $items = $run->items()
            ->select([
                'id',
                'email_canonical_cutover_run_id',
                'item_key',
                'item_kind',
                'component_key',
                'email_account_id',
                'source_email_message_id',
                'evidence_complete',
                'previous_read_mode',
                'proposed_read_mode',
                'status',
                'error_code',
            ])
            ->orderBy('item_key')
            ->paginate(100);

        return view('email::Admin.Cutover.show', compact('accounts', 'items', 'run'));
    }

    public function apply(
        Request $request,
        string $run,
        ApplyEmailCanonicalCutover $apply,
    ): RedirectResponse {
        $actor = $this->operatorForMutation($request);
        $run = $this->runForActor($run, $actor);
        $request->validate([
            'confirmation' => ['required', 'string', Rule::in(['APPLY RUN #'.$run->id])],
        ]);
        $apply->handle($run, $actor);

        return back()->with('success', 'The exact durable cutover preview was applied locally.');
    }

    public function rollback(
        Request $request,
        string $run,
        RollbackEmailCanonicalCutover $rollback,
    ): RedirectResponse {
        $actor = $this->operatorForMutation($request);
        $run = $this->runForActor($run, $actor);
        $request->validate([
            'confirmation' => ['required', 'string', Rule::in(['ROLLBACK RUN #'.$run->id])],
        ]);
        $rollback->handle($run, $actor);

        return back()->with('success', 'The exact durable cutover run was rolled back locally.');
    }

    private function operator(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $actor->isActive()
            || $actor->isSystemActor()
            || ! $actor->can('email.canonical_cutover_manage')
            || ! $actor->can('email.mailbox_sync_manage')) {
            throw new AuthorizationException('Canonical cutover maintenance is not available.');
        }

        return $actor;
    }

    private function operatorForMutation(Request $request): User
    {
        $actor = $this->operator($request);
        abort_unless($this->schemaReady(), 503, 'Canonical cutover schema is not available.');

        return $actor;
    }

    /** @return Collection<int,EmailAccount> */
    private function viewableAccounts(User $actor): Collection
    {
        return EmailAccount::query()
            ->where('is_active', true)
            ->orderBy('address')
            ->get(['id', 'address', 'account_kind', 'is_active', 'owner_id'])
            ->filter(fn (EmailAccount $account): bool => $this->accessDecisions
                ->resolve($actor, $account, MailboxAccess::VIEW)
                ->allowed)
            ->values();
    }

    /** @return Collection<int,EmailAccount> */
    private function authorizeRun(EmailCanonicalCutoverRun $run, User $actor): Collection
    {
        return $this->authorizeAccountScope($run->account_scope_json, $actor);
    }

    /** @param array<int,mixed>|null $scope
     * @return Collection<int,EmailAccount>
     */
    private function authorizeAccountScope(?array $scope, User $actor): Collection
    {
        $accountIds = collect($scope)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values();
        $accounts = EmailAccount::query()->whereKey($accountIds)->get()->keyBy('id');
        if ($accountIds->isEmpty() || $accounts->count() !== $accountIds->count()) {
            abort(404);
        }

        foreach ($accountIds as $accountId) {
            $account = $accounts->get($accountId);
            if (! $account?->is_active
                || ! $this->accessDecisions->resolve($actor, $account, MailboxAccess::VIEW)->allowed) {
                abort(404);
            }
        }

        return $accounts;
    }

    private function runForActor(string $run, User $actor): EmailCanonicalCutoverRun
    {
        $run = EmailCanonicalCutoverRun::query()->findOrFail((int) $run);
        $this->authorizeRun($run, $actor);

        return $run;
    }

    /** @return array<string,mixed> */
    private function boundedSourceScope(Request $request): array
    {
        return $request->validate([
            'account_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_ACCOUNT_SCOPE],
            'account_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'min_message_id' => ['nullable', 'integer', 'min:1'],
            'max_message_id' => ['nullable', 'integer', 'min:1', 'gte:min_message_id'],
            'item_cap' => [
                'nullable',
                'integer',
                'min:1',
                'max:'.PreviewEmailCanonicalCutover::MAX_ITEM_CAP,
            ],
        ]);
    }

    /** @return list<int> */
    private function candidateIds(string $value): array
    {
        if (preg_match('/\A[\d\s,]+\z/', $value) !== 1) {
            throw ValidationException::withMessages([
                'candidate_ids' => 'Use only comma- or whitespace-separated candidate IDs.',
            ]);
        }

        $ids = collect(preg_split('/[\s,]+/', trim($value)) ?: [])
            ->filter()
            ->map(fn (string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($ids === [] || count($ids) > self::MAX_CANDIDATE_EDGES) {
            throw ValidationException::withMessages([
                'candidate_ids' => 'Choose a non-empty candidate set within the component cap.',
            ]);
        }

        return $ids;
    }

    private function previewRedirect(EmailCanonicalCutoverRun $run, string $message): RedirectResponse
    {
        return redirect()
            ->route('tech.admin.settings.email.canonical-cutover.show', $run)
            ->with('success', $message.' Review the frozen items before apply.');
    }

    /** @param list<int>|array<int,mixed>|null $scope
     * @param  list<int>  $allowedIds
     */
    private function scopeIsAllowed(?array $scope, array $allowedIds): bool
    {
        $scope = collect($scope)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0);

        return $scope->isNotEmpty()
            && $scope->every(fn (int $id): bool => in_array($id, $allowedIds, true));
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('email_canonical_messages')
            && Schema::hasTable('email_canonical_message_attachments')
            && Schema::hasTable('email_canonical_message_sources')
            && Schema::hasTable('email_canonical_cutover_runs')
            && Schema::hasTable('email_canonical_cutover_items')
            && Schema::hasTable('email_canonical_read_modes')
            && Schema::hasTable('email_canonical_parity_attestations')
            && Schema::hasTable('email_canonical_parity_attestation_items')
            && Schema::hasColumn('email_mailbox_placements', 'canonical_email_message_id');
    }
}
