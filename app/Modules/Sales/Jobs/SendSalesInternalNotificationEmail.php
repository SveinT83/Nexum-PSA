<?php

namespace App\Modules\Sales\Jobs;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\EmailTemplateRenderer;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Sales\Models\SalesActivity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendSalesInternalNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $salesActivityId) {}

    public function handle(
        DefaultEmailAccountResolver $accountResolver,
        EmailTemplateRenderer $renderer,
        SmtpAccountMailer $mailer,
    ): void {
        $activity = SalesActivity::with(['opportunity', 'actor'])->find($this->salesActivityId);
        $notifyUserId = $activity?->metadata['notify_user_id'] ?? null;
        $recipient = $notifyUserId ? User::query()->whereKey($notifyUserId)->where('status', User::STATUS_ACTIVE)->first() : null;

        if (! $activity || $activity->type !== 'internal_note' || ! $recipient?->email) {
            return;
        }

        $account = $accountResolver->forScope('sales');

        if (! $account) {
            $this->log(null, $activity->id, 'error', 'SALES_INTERNAL_NOTIFY_NO_ACCOUNT', 'No active sales outbound email account is configured.');
            return;
        }

        $template = EmailTemplate::query()
            ->where('scope', 'sales')
            ->where('key', 'sales_internal_note')
            ->where('is_active', true)
            ->first();

        if (! $template) {
            $this->log($account->id, $activity->id, 'error', 'SALES_INTERNAL_NOTIFY_NO_TEMPLATE', 'No active sales_internal_note template exists.');
            return;
        }

        $opportunity = $activity->opportunity;
        $author = $activity->actor?->name ?? 'A seller';
        $rendered = $renderer->render($template, [
            'opportunity_key' => $opportunity->opportunity_key,
            'opportunity_title' => $opportunity->title,
            'client_name' => $opportunity->client?->name ?? '',
            'author_name' => $author,
            'note_body' => $activity->body,
        ]);

        $messageId = $mailer->send($account, $recipient->email, $recipient->name, $rendered['subject'], $rendered['html'], $rendered['text']);

        $this->log($account->id, $activity->id, 'info', 'SALES_INTERNAL_NOTIFY_SENT', 'Sales internal note notification sent.', [
            'opportunity_id' => $opportunity->id,
            'opportunity_key' => $opportunity->opportunity_key,
            'to' => $recipient->email,
            'rfc_message_id' => $messageId,
        ], $messageId);
    }

    private function log(?int $accountId, int $activityId, string $level, string $code, string $message, array $context = [], ?string $rfcMessageId = null): void
    {
        EmailLog::create([
            'direction' => 'outbound',
            'account_id' => $accountId,
            'scope' => 'sales',
            'level' => $level,
            'code' => $code,
            'message' => $message,
            'context_json' => array_merge(['sales_activity_id' => $activityId], $context),
            'rfc_message_id' => $rfcMessageId,
        ]);
    }
}
