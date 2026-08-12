<?php

namespace App\Modules\Sales\Actions;

use App\Models\Core\User;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuote;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Models\SalesSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnsureSalesQuoteDraft
{
    public function handle(SalesOpportunity $opportunity, User $actor, array $options = []): SalesQuoteVersion
    {
        $opportunity->loadMissing('currentQuoteVersion');

        if ($opportunity->currentQuoteVersion?->isEditable()) {
            return $opportunity->currentQuoteVersion;
        }

        return DB::transaction(function () use ($opportunity, $actor, $options): SalesQuoteVersion {
            $previous = $opportunity->currentQuoteVersion?->loadMissing(['lines', 'optionGroups', 'acknowledgements']);
            $previousStatus = $previous?->status;
            $revisionMode = (string) ($options['mode'] ?? 'revision');
            $isAdditionalAfterAcceptance = $revisionMode === 'additional_after_acceptance'
                && $previousStatus === 'accepted';
            $quote = $opportunity->quotes()->first() ?: SalesQuote::query()->create([
                'opportunity_id' => $opportunity->id,
                'quote_key' => 'Q-'.now()->format('Y').'-'.Str::upper(Str::random(6)),
                'status' => 'draft',
            ]);
            $nextVersion = ((int) $quote->versions()->max('version_number')) + 1;

            if ($previousStatus === 'sent') {
                $previous->forceFill([
                    'status' => 'superseded',
                    'updated_by' => $actor->id,
                    'snapshots' => array_merge($previous->snapshots ?: [], [
                        'superseded' => [
                            'at' => now()->toISOString(),
                            'by' => $actor->id,
                            'reason' => $options['reason'] ?? 'A revised quote draft was created.',
                        ],
                    ]),
                ])->save();
            }

            $version = SalesQuoteVersion::query()->create([
                'quote_id' => $quote->id,
                'version_number' => $nextVersion,
                'status' => 'draft',
                'secure_token' => Str::random(64),
                'title' => $isAdditionalAfterAcceptance
                    ? 'Additional approval - '.$opportunity->title
                    : ($previous?->title ?? $opportunity->title),
                'intro_text' => $isAdditionalAfterAcceptance
                    ? 'This is an additional quote for scope discovered after the earlier accepted quote.'
                    : ($previous?->intro_text ?? 'Thank you for the opportunity to provide this quote.'),
                'scope_text' => $isAdditionalAfterAcceptance
                    ? 'Additional scope for Ticket-related delivery.'
                    : ($previous?->scope_text ?? $opportunity->needs),
                'assumptions_text' => $previous?->assumptions_text ?? 'Prices are shown excluding VAT unless otherwise stated.',
                'exclusions_text' => $isAdditionalAfterAcceptance ? null : $previous?->exclusions_text,
                'next_steps_text' => $previous?->next_steps_text ?? 'Please accept the quote or ask a question if anything should be clarified.',
                'expires_at' => now()->addDays((int) SalesSetting::get('quote_expiry_days', 30))->toDateString(),
                'snapshots' => array_filter([
                    'revision_mode' => $isAdditionalAfterAcceptance ? 'additional_after_acceptance' : 'revision',
                    'supersedes_quote_version_id' => $previousStatus === 'sent' ? $previous->id : null,
                    'additional_after_accepted_quote_version_id' => $isAdditionalAfterAcceptance ? $previous->id : null,
                ], static fn ($value): bool => $value !== null),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $groupIdMap = [];
            if (! $isAdditionalAfterAcceptance) {
                foreach ($previous?->optionGroups ?? [] as $group) {
                    $newGroup = $group->replicate(['id', 'quote_version_id', 'created_at', 'updated_at']);
                    $newGroup->forceFill(['quote_version_id' => $version->id])->save();
                    $groupIdMap[$group->id] = $newGroup->id;
                }

                $lineIdMap = [];
                foreach ($previous?->lines ?? [] as $line) {
                    $newLine = $line->replicate(['id', 'quote_version_id', 'created_at', 'updated_at']);
                    $newLine->forceFill([
                        'quote_version_id' => $version->id,
                        'option_group_id' => $line->option_group_id ? ($groupIdMap[$line->option_group_id] ?? null) : null,
                    ])->save();
                    $lineIdMap[$line->id] = $newLine->id;
                }

                foreach ($previous?->acknowledgements ?? [] as $acknowledgement) {
                    $acknowledgement
                        ->replicate(['id', 'quote_version_id', 'created_at', 'updated_at'])
                        ->forceFill([
                            'quote_version_id' => $version->id,
                            'quote_line_id' => $acknowledgement->quote_line_id ? ($lineIdMap[$acknowledgement->quote_line_id] ?? null) : null,
                        ])
                        ->save();
                }
            }

            $quote->forceFill(['current_version_id' => $version->id, 'status' => 'draft'])->save();
            $opportunity->forceFill(['current_quote_version_id' => $version->id])->save();

            if ($previous) {
                SalesActivity::query()->create([
                    'opportunity_id' => $opportunity->id,
                    'actor_id' => $actor->id,
                    'type' => $isAdditionalAfterAcceptance ? 'quote_additional_draft_created' : 'quote_revised',
                    'subject' => $isAdditionalAfterAcceptance ? 'Additional quote draft created' : 'Quote revision draft created',
                    'body' => $isAdditionalAfterAcceptance
                        ? 'A separate additional quote draft was created after an accepted quote.'
                        : 'A quote revision draft was created and the previous sent quote was superseded if needed.',
                    'metadata' => [
                        'previous_quote_version_id' => $previous->id,
                        'quote_version_id' => $version->id,
                        'revision_mode' => $isAdditionalAfterAcceptance ? 'additional_after_acceptance' : 'revision',
                    ],
                ]);
            }

            return $version;
        });
    }
}
