<?php

namespace App\Modules\LeadIntelligence\Actions;

use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Services\AiChatResponder;
use App\Modules\LeadIntelligence\Models\LeadResearchRun;
use App\Modules\LeadIntelligence\Models\LeadSegment;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class ReviewLeadCandidateWithAi
{
    private const TIMEOUT_SECONDS = 90;

    public function __construct(private readonly AiChatResponder $responder)
    {
    }

    public function handle(array $candidate, LeadSegment $segment, LeadResearchRun $run, array $settings): array
    {
        if (! (bool) ($settings['ai_candidate_review_enabled'] ?? false)) {
            return $this->fallback('disabled', 'AI candidate review is disabled.');
        }

        $agent = $this->agent();

        if (! $agent) {
            return $this->fallback('unavailable', 'No active Lead Intelligence AI agent is available.', $settings);
        }

        try {
            $reply = $this->responder->complete($agent, [
                ['role' => 'system', 'content' => $this->systemPrompt($settings)],
                ['role' => 'user', 'content' => json_encode($this->context($candidate, $segment, $run, $settings), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            ], self::TIMEOUT_SECONDS);

            $review = $this->sanitize($this->decodeJson($reply), $candidate);
            $review['used_ai'] = true;
            $review['status'] = 'reviewed';
            $review['raw_reply'] = Str::limit($reply, 5000, '');

            return $review;
        } catch (\Throwable $exception) {
            return $this->fallback('error', 'AI candidate review failed: '.$exception->getMessage(), $settings);
        }
    }

    public function apply(array $candidate, array $review): array
    {
        if (($review['decision'] ?? 'promote') !== 'promote') {
            return $candidate;
        }

        $candidate['company']['score'] = (int) ($review['company_score'] ?? $candidate['company']['score'] ?? 0);
        $candidate['company']['confidence'] = (int) ($review['company_score'] ?? $candidate['company']['confidence'] ?? 0);
        $contactReviews = collect((array) ($review['contacts'] ?? []))
            ->keyBy(fn (array $contact): string => Str::lower((string) ($contact['email'] ?? '')));
        $sharedEmail = Str::lower((string) data_get($candidate, 'company.shared_email'));

        if ($sharedEmail !== '' && $contactReviews->has($sharedEmail) && ($contactReviews->get($sharedEmail)['decision'] ?? 'promote') !== 'promote') {
            unset($candidate['company']['shared_email']);
        }

        $candidate['contacts'] = collect((array) ($candidate['contacts'] ?? []))
            ->map(function (array $contact) use ($contactReviews): array {
                $email = Str::lower((string) ($contact['email'] ?? ''));
                $review = $contactReviews->get($email);

                if (! $review || ($review['decision'] ?? 'promote') !== 'promote') {
                    $contact['ai_review_decision'] = $review['decision'] ?? 'not_reviewed';

                    return $contact;
                }

                if (filled($review['role'] ?? null)) {
                    $contact['role'] = $review['role'];
                }

                $contact['score'] = (int) ($review['contact_score'] ?? $contact['score'] ?? 0);
                $contact['confidence'] = (int) ($review['contact_score'] ?? $contact['confidence'] ?? 0);
                $contact['ai_review_decision'] = 'promote';
                $contact['ai_review_reason'] = $review['reason'] ?? null;

                return $contact;
            })
            ->filter(fn (array $contact): bool => ($contact['ai_review_decision'] ?? 'promote') === 'promote')
            ->values()
            ->all();

        return $candidate;
    }

    private function agent(): ?AiAgent
    {
        return AiAgent::query()
            ->with('provider')
            ->where('is_active', true)
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->first(fn (AiAgent $agent): bool => in_array('lead_intelligence', $agent->default_domains ?? [], true));
    }

    private function systemPrompt(array $settings): string
    {
        return trim((string) ($settings['ai_candidate_review_prompt'] ?? ''));
    }

    private function context(array $candidate, LeadSegment $segment, LeadResearchRun $run, array $settings): array
    {
        return [
            'run_id' => $run->id,
            'segment' => [
                'name' => $segment->name,
                'goal_prompt' => $segment->description,
                'geography' => $segment->geography_json ?: [],
                'industries' => $segment->industries_json ?: [],
                'nace_codes' => $segment->nace_codes_json ?: [],
                'keywords' => $segment->keywords_json ?: [],
                'excluded_keywords' => $segment->excluded_keywords_json ?: [],
                'target_roles' => $segment->target_roles_json ?: [],
            ],
            'policy' => [
                'allowed_roles' => $settings['allowed_roles'] ?? [],
                'minimum_company_score' => $settings['minimum_company_score'] ?? 0,
                'minimum_contact_score' => $settings['minimum_contact_score'] ?? 0,
                'allow_generic_company_emails' => $settings['allow_generic_company_emails'] ?? false,
                'allow_role_based_emails' => $settings['allow_role_based_emails'] ?? false,
                'allow_named_work_emails' => $settings['allow_named_work_emails'] ?? false,
            ],
            'candidate' => [
                'company' => Arr::only((array) ($candidate['company'] ?? []), [
                    'name',
                    'org_no',
                    'website',
                    'shared_email',
                    'source_type',
                    'source_url',
                    'source_title',
                    'excerpt',
                    'score',
                    'confidence',
                ]),
                'contacts' => collect((array) ($candidate['contacts'] ?? []))
                    ->map(fn (array $contact): array => Arr::only($contact, [
                        'name',
                        'email',
                        'role',
                        'source_type',
                        'source_url',
                        'source_title',
                        'excerpt',
                        'score',
                        'confidence',
                    ]))
                    ->values()
                    ->all(),
            ],
        ];
    }

    private function decodeJson(string $reply): array
    {
        $json = trim($reply);

        if (str_starts_with($json, '```')) {
            $json = preg_replace('/^```(?:json)?\s*/i', '', $json);
            $json = preg_replace('/\s*```$/', '', (string) $json);
        }

        $decoded = json_decode((string) $json, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('AI did not return valid JSON content.');
        }

        return $decoded;
    }

    private function sanitize(array $payload, array $candidate): array
    {
        $allowedEmails = $this->allowedEmails($candidate);
        $decision = $this->decision($payload['decision'] ?? 'review');

        return [
            'decision' => $decision,
            'company_score' => $this->score($payload['company_score'] ?? 0),
            'company_is_b2b' => (bool) ($payload['company_is_b2b'] ?? false),
            'reason' => Str::limit(trim((string) ($payload['reason'] ?? 'AI review returned no reason.')), 500, ''),
            'contacts' => collect((array) ($payload['contacts'] ?? []))
                ->map(function (array $contact) use ($allowedEmails): ?array {
                    $email = Str::lower(trim((string) ($contact['email'] ?? '')));

                    if (! in_array($email, $allowedEmails, true)) {
                        return null;
                    }

                    return [
                        'email' => $email,
                        'decision' => $this->decision($contact['decision'] ?? 'review'),
                        'contact_score' => $this->score($contact['contact_score'] ?? 0),
                        'role' => filled($contact['role'] ?? null) ? Str::limit(trim((string) $contact['role']), 120, '') : null,
                        'reason' => Str::limit(trim((string) ($contact['reason'] ?? '')), 500, ''),
                    ];
                })
                ->filter()
                ->values()
                ->all(),
        ];
    }

    private function allowedEmails(array $candidate): array
    {
        return collect([
            data_get($candidate, 'company.shared_email'),
            ...collect((array) ($candidate['contacts'] ?? []))->pluck('email')->all(),
        ])
            ->map(fn (mixed $email): string => Str::lower(trim((string) $email)))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    private function decision(mixed $decision): string
    {
        $decision = Str::lower(trim((string) $decision));

        return in_array($decision, ['promote', 'skip', 'review'], true) ? $decision : 'review';
    }

    private function score(mixed $score): int
    {
        return max(0, min(100, (int) $score));
    }

    private function fallback(string $status, string $reason, array $settings = []): array
    {
        $required = (bool) ($settings['ai_candidate_review_required'] ?? false);

        return [
            'used_ai' => false,
            'status' => $status,
            'decision' => $required ? 'review' : 'promote',
            'company_score' => null,
            'company_is_b2b' => null,
            'reason' => $reason,
            'contacts' => [],
        ];
    }
}
