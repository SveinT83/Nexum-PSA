<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Actions\SendEmailComposerMessage;
use App\Modules\Email\Models\EmailSignature;
use App\Modules\System\Support\CompanyProfileSettings;
use App\Modules\UserManagement\Models\UserProfile;
use Illuminate\Support\Str;

class EmailSignatureRenderer
{
    public const SIGNATURE_START_MARKER = '<!-- nexum-mail-signature:start';

    public const SIGNATURE_END_MARKER = '<!-- nexum-mail-signature:end -->';

    public const FORWARDED_MESSAGE_MARKER = '<!-- nexum-forwarded-message:start -->';

    private const FORWARDED_MESSAGE_HEADING = '<p><strong>Forwarded message</strong></p>';

    public function __construct(
        private readonly CompanyProfileSettings $companyProfile,
    ) {}

    public function signatureFor(User $user): EmailSignature
    {
        $signature = EmailSignature::query()->where('user_id', $user->id)->first();

        if ($signature) {
            return $signature;
        }

        return new EmailSignature([
            'user_id' => $user->id,
            'name' => 'Default',
            'body_html' => $this->defaultBodyHtml(),
            'body_text' => BodyNormalizer::toText($this->renderTokens($this->defaultBodyHtml(), $user)),
            'use_on_compose' => true,
            'use_on_reply' => true,
            'use_on_reply_all' => true,
            'use_on_forward' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): EmailSignature
    {
        $existing = $this->signatureFor($user);
        $name = trim((string) ($data['name'] ?? ($existing->name ?: 'Default'))) ?: 'Default';
        $bodyHtml = array_key_exists('body_html', $data)
            ? trim((string) ($data['body_html'] ?? ''))
            : (string) ($existing->body_html ?: $this->defaultBodyHtml());

        if ((bool) ($data['use_default_template'] ?? false)) {
            $bodyHtml = $this->defaultBodyHtml();
        }

        $bodyHtml = HtmlSanitizer::sanitize($bodyHtml) ?: '';
        $rendered = $this->renderBodyHtml($this->temporarySignature($user, $bodyHtml), $user);

        return EmailSignature::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'name' => $name,
                'body_html' => $bodyHtml,
                'body_text' => BodyNormalizer::toText($rendered),
                'use_on_compose' => (bool) ($data['use_on_compose'] ?? false),
                'use_on_reply' => (bool) ($data['use_on_reply'] ?? false),
                'use_on_reply_all' => (bool) ($data['use_on_reply_all'] ?? false),
                'use_on_forward' => (bool) ($data['use_on_forward'] ?? false),
                'created_by' => $existing->exists ? $existing->created_by : $user->id,
                'updated_by' => $user->id,
            ],
        );
    }

    /**
     * @return array{
     *     body_html: string,
     *     body_text: string,
     *     applied: bool,
     *     signature_id: int|null,
     *     signature_name: string|null,
     *     signature_source: string|null
     * }
     */
    public function appendForMode(string $bodyHtml, string $mode, User $user): array
    {
        $alreadySigned = Str::contains($bodyHtml, self::SIGNATURE_START_MARKER);
        $bodyHtml = HtmlSanitizer::sanitize($bodyHtml) ?: '';
        $signature = $this->signatureFor($user);

        if (! $signature->enabledForMode($mode) || $alreadySigned) {
            return $this->appendResult($bodyHtml, false, $signature, null);
        }

        $signatureHtml = $this->renderBodyHtml($signature, $user);

        if (! $this->hasRenderableSignature($signatureHtml)) {
            return $this->appendResult($bodyHtml, false, $signature, null);
        }

        $signatureBlock = $this->signatureBlock($signatureHtml, $signature);
        $combinedHtml = $this->insertSignatureBlock($bodyHtml, $signatureBlock, $mode);

        return $this->appendResult(
            $combinedHtml,
            true,
            $signature,
            $signature->exists ? 'stored_signature' : 'default_template',
        );
    }

    public function defaultBodyHtml(): string
    {
        return implode('', [
            '<p>Mvh<br>{user.name}<br>{user.phone}</p>',
            '<p>{company.logo}</p>',
            '<p><strong>{company.name}</strong><br>{company.phone}<br>{company.website}</p>',
        ]);
    }

    public function renderBodyHtml(EmailSignature $signature, User $user): string
    {
        $bodyHtml = trim((string) ($signature->body_html ?: $this->defaultBodyHtml()));

        return HtmlSanitizer::sanitize($this->renderTokens($bodyHtml, $user)) ?: '';
    }

    /**
     * @return array<string, string>
     */
    public function tokenDescriptions(): array
    {
        return [
            '{user.name}' => 'Technician name',
            '{user.email}' => 'Technician email',
            '{user.phone}' => 'Technician work phone',
            '{company.name}' => 'Company name',
            '{company.phone}' => 'Company phone',
            '{company.website}' => 'Company website link',
            '{company.logo}' => 'Company logo image',
        ];
    }

    private function renderTokens(string $bodyHtml, User $user): string
    {
        $profile = $this->profileFor($user);
        $company = $this->companyProfile->get();
        $companyName = (string) ($company['company_name'] ?? config('app.name', 'Nexum PSA'));
        $website = trim((string) ($company['website'] ?? ''));

        return strtr($bodyHtml, [
            '{user.name}' => e((string) $user->name),
            '{user.email}' => e((string) $user->email),
            '{user.phone}' => e((string) ($profile?->work_phone ?: $user->phone_work ?: $user->phone_private ?: '')),
            '{company.name}' => e($companyName),
            '{company.phone}' => e((string) ($company['phone'] ?? '')),
            '{company.website}' => $this->websiteLink($website),
            '{company.logo}' => $this->companyLogoHtml($company, $companyName),
        ]);
    }

    private function websiteLink(string $website): string
    {
        if ($website === '') {
            return '';
        }

        $href = Str::startsWith($website, ['http://', 'https://'])
            ? $website
            : 'https://'.$website;

        return '<a href="'.e($href).'">'.e($website).'</a>';
    }

    /**
     * @param  array<string, mixed>  $company
     */
    private function companyLogoHtml(array $company, string $companyName): string
    {
        $logoUrl = (string) ($company['logo_light_url'] ?? $company['logo_url'] ?? '');

        if ($logoUrl === '') {
            return '';
        }

        $src = Str::startsWith($logoUrl, ['http://', 'https://'])
            ? $logoUrl
            : url($logoUrl);

        return '<img src="'.e($src).'" alt="'.e($companyName).' logo" style="max-width:160px;height:auto;">';
    }

    private function profileFor(User $user): ?UserProfile
    {
        if ($user->relationLoaded('profile')) {
            return $user->profile;
        }

        return UserProfile::query()->where('user_id', $user->id)->first();
    }

    private function temporarySignature(User $user, string $bodyHtml): EmailSignature
    {
        return new EmailSignature([
            'user_id' => $user->id,
            'body_html' => $bodyHtml,
        ]);
    }

    private function hasRenderableSignature(string $signatureHtml): bool
    {
        return trim((string) BodyNormalizer::toText($signatureHtml)) !== ''
            || Str::contains($signatureHtml, '<img');
    }

    private function signatureBlock(string $signatureHtml, EmailSignature $signature): string
    {
        $key = $signature->exists ? (string) $signature->id : 'default';

        return "\n"
            .self::SIGNATURE_START_MARKER.':'.$key.' -->'
            .'<div class="nexum-mail-signature" data-nexum-mail-signature="true">'
            .$signatureHtml
            .'</div>'
            .self::SIGNATURE_END_MARKER
            ."\n";
    }

    private function insertSignatureBlock(string $bodyHtml, string $signatureBlock, string $mode): string
    {
        if ($mode === SendEmailComposerMessage::MODE_FORWARD) {
            if (Str::contains($bodyHtml, self::FORWARDED_MESSAGE_MARKER)) {
                return Str::replaceFirst(self::FORWARDED_MESSAGE_MARKER, $signatureBlock.self::FORWARDED_MESSAGE_MARKER, $bodyHtml);
            }

            if (Str::contains($bodyHtml, self::FORWARDED_MESSAGE_HEADING)) {
                return Str::replaceFirst(self::FORWARDED_MESSAGE_HEADING, $signatureBlock.self::FORWARDED_MESSAGE_HEADING, $bodyHtml);
            }
        }

        return rtrim($bodyHtml).$signatureBlock;
    }

    /**
     * @return array{
     *     body_html: string,
     *     body_text: string,
     *     applied: bool,
     *     signature_id: int|null,
     *     signature_name: string|null,
     *     signature_source: string|null
     * }
     */
    private function appendResult(
        string $bodyHtml,
        bool $applied,
        ?EmailSignature $signature,
        ?string $source,
    ): array {
        return [
            'body_html' => $bodyHtml,
            'body_text' => BodyNormalizer::toText($bodyHtml) ?: '',
            'applied' => $applied,
            'signature_id' => $signature?->exists ? $signature->id : null,
            'signature_name' => $signature?->name,
            'signature_source' => $source,
        ];
    }
}
