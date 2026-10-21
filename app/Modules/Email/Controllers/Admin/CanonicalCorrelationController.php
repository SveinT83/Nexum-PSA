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
        $data = app()->call([app(\App\Modules\Email\Services\EmailCanonicalCorrelationOperations::class), 'index'], ['request' => $request]);

        return view('email::Admin.Correlation.index', $data);
    }

    public function store(Request $request, StartEmailCanonicalCorrelationRun $start): RedirectResponse
    {
        $data = app()->call([app(\App\Modules\Email\Services\EmailCanonicalCorrelationOperations::class), 'store'], ['request' => $request]);
        $run = $data['run'];

        return redirect()
            ->route('tech.admin.settings.email.correlation.show', $run)
            ->with('success', 'The bounded local shadow-correlation run was queued.');
    }

    public function show(
        Request $request,
        string $run,
    ): View {
        $data = app()->call([app(\App\Modules\Email\Services\EmailCanonicalCorrelationOperations::class), 'show'], ['request' => $request, 'run' => $run]);

        return view('email::Admin.Correlation.show', $data);
    }

    public function inspect(
        Request $request,
        string $candidate,
        InspectEmailCanonicalCorrelationCandidate $inspect,
    ): View {
        $data = app()->call([app(\App\Modules\Email\Services\EmailCanonicalCorrelationOperations::class), 'inspect'], ['request' => $request, 'candidate' => $candidate]);

        return view('email::Admin.Correlation.inspect', $data);
    }

    public function review(
        Request $request,
        string $candidate,
        ReviewEmailCanonicalCorrelationCandidate $review,
    ): RedirectResponse {
        $data = app()->call([app(\App\Modules\Email\Services\EmailCanonicalCorrelationOperations::class), 'review'], ['request' => $request, 'candidate' => $candidate]);
        $candidate = $data['candidate'];

        return back()->with('success', 'The metadata-only shadow review was recorded.');
    }

    public function resume(
        Request $request,
        string $run,
        ResumeEmailCanonicalCorrelationRun $resume,
    ): RedirectResponse {
        $data = app()->call([app(\App\Modules\Email\Services\EmailCanonicalCorrelationOperations::class), 'resume'], ['request' => $request, 'run' => $run]);
        $run = $data['run'];

        return back()->with('success', 'The bounded shadow run was queued from its durable cursor.');
    }

    public function cancel(
        Request $request,
        string $run,
        CancelEmailCanonicalCorrelationRun $cancel,
    ): RedirectResponse {
        $data = app()->call([app(\App\Modules\Email\Services\EmailCanonicalCorrelationOperations::class), 'cancel'], ['request' => $request, 'run' => $run]);
        $run = $data['run'];

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
