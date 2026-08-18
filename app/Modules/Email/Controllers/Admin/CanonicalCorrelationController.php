<?php

namespace App\Modules\Email\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Email\Actions\CancelEmailCanonicalCorrelationRun;
use App\Modules\Email\Actions\InspectEmailCanonicalCorrelationCandidate;
use App\Modules\Email\Actions\ResumeEmailCanonicalCorrelationRun;
use App\Modules\Email\Actions\ReviewEmailCanonicalCorrelationCandidate;
use App\Modules\Email\Actions\StartEmailCanonicalCorrelationRun;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailCanonicalCorrelationCandidate;
use App\Modules\Email\Models\EmailCanonicalCorrelationRun;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CanonicalCorrelationController extends Controller
{
    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly ResolveMailboxAccessDecision $accessDecisions,
    ) {}

    public function index(Request $request): View
    {
        $actor = $this->operator($request);
        $accounts = $this->mailboxAccess
            ->scopeAccounts(EmailAccount::query(), $actor, MailboxAccess::VIEW)
            ->orderBy('address')
            ->get(['id', 'address', 'account_kind']);
        $allowedIds = $accounts->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $runs = EmailCanonicalCorrelationRun::query()
            ->where('requested_by', $actor->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->filter(fn (EmailCanonicalCorrelationRun $run): bool => collect($run->account_scope_json)
                ->every(fn (mixed $id): bool => in_array((int) $id, $allowedIds, true)));

        return view('email::Admin.Correlation.index', compact('accounts', 'runs'));
    }

    public function store(Request $request, StartEmailCanonicalCorrelationRun $start): RedirectResponse
    {
        $actor = $this->operator($request);
        $data = $request->validate([
            'account_ids' => ['required', 'array', 'min:1', 'max:25'],
            'account_ids.*' => ['required', 'integer', 'distinct'],
            'min_message_id' => ['nullable', 'integer', 'min:1'],
            'max_message_id' => ['nullable', 'integer', 'min:1', 'gte:min_message_id'],
            'message_cap' => ['nullable', 'integer'],
            'group_cap' => ['nullable', 'integer'],
            'pair_cap' => ['nullable', 'integer'],
            'per_group_cap' => ['nullable', 'integer'],
        ]);
        $run = $start->handle($actor, $data['account_ids'], array_filter([
            'min_message_id' => $data['min_message_id'] ?? null,
            'max_message_id' => $data['max_message_id'] ?? null,
            'message_cap' => $data['message_cap'] ?? null,
            'group_cap' => $data['group_cap'] ?? null,
            'pair_cap' => $data['pair_cap'] ?? null,
            'per_group_cap' => $data['per_group_cap'] ?? null,
        ], fn (mixed $value): bool => $value !== null));

        return redirect()
            ->route('tech.admin.settings.email.correlation.show', $run)
            ->with('success', 'The bounded local shadow-correlation run was queued.');
    }

    public function show(
        Request $request,
        string $run,
    ): View {
        $actor = $this->operator($request);
        $run = EmailCanonicalCorrelationRun::query()
            ->where('requested_by', $actor->id)
            ->findOrFail((int) $run);
        $accounts = $this->authorizeRun($run, $actor);
        $candidates = $run->candidates()
            ->withExists(['inspections as inspected_by_current_user' => fn ($inspections) => $inspections
                ->where('inspected_by', $actor->id)])
            ->orderBy('candidate_class')
            ->orderBy('id')
            ->paginate(50);

        return view('email::Admin.Correlation.show', compact('run', 'accounts', 'candidates'));
    }

    public function inspect(
        Request $request,
        string $candidate,
        InspectEmailCanonicalCorrelationCandidate $inspect,
    ): View {
        $actor = $this->operator($request);
        $candidate = EmailCanonicalCorrelationCandidate::query()
            ->whereHas('run', fn ($runs) => $runs->where('requested_by', $actor->id))
            ->findOrFail((int) $candidate);
        $inspection = $inspect->handle($candidate, $actor);

        return view('email::Admin.Correlation.inspect', $inspection);
    }

    public function review(
        Request $request,
        string $candidate,
        ReviewEmailCanonicalCorrelationCandidate $review,
    ): RedirectResponse {
        $actor = $this->operator($request);
        $candidate = EmailCanonicalCorrelationCandidate::query()
            ->whereHas('run', fn ($runs) => $runs->where('requested_by', $actor->id))
            ->findOrFail((int) $candidate);
        $data = $request->validate([
            'review_state' => ['required', 'string'],
            'reason_code' => ['required', 'string', 'max:80'],
        ]);
        $review->handle($candidate, $actor, $data['review_state'], $data['reason_code']);

        return back()->with('success', 'The metadata-only shadow review was recorded.');
    }

    public function resume(
        Request $request,
        string $run,
        ResumeEmailCanonicalCorrelationRun $resume,
    ): RedirectResponse {
        $actor = $this->operator($request);
        $run = EmailCanonicalCorrelationRun::query()
            ->where('requested_by', $actor->id)
            ->findOrFail((int) $run);
        $resume->handle($run, $actor);

        return back()->with('success', 'The bounded shadow run was queued from its durable cursor.');
    }

    public function cancel(
        Request $request,
        string $run,
        CancelEmailCanonicalCorrelationRun $cancel,
    ): RedirectResponse {
        $actor = $this->operator($request);
        $run = EmailCanonicalCorrelationRun::query()
            ->where('requested_by', $actor->id)
            ->findOrFail((int) $run);
        $cancel->handle($run, $actor);

        return back()->with('success', 'The shadow run was cancelled.');
    }

    private function operator(Request $request): User
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $actor->isActive()
            || $actor->isSystemActor()
            || ! $actor->can('email.mailbox_sync_manage')) {
            throw new AuthorizationException('Canonical correlation maintenance is not available.');
        }

        return $actor;
    }

    /** @return \Illuminate\Support\Collection<int, EmailAccount> */
    private function authorizeRun(EmailCanonicalCorrelationRun $run, User $actor)
    {
        $accountIds = collect($run->account_scope_json)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $accounts = EmailAccount::query()->whereIn('id', $accountIds)->get()->keyBy('id');
        if ($accounts->count() !== $accountIds->count()) {
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
}
