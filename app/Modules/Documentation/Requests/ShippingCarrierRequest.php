<?php

namespace App\Modules\Documentation\Requests;

use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Support\ShippingTrackingLinkResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ShippingCarrierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => Str::lower(trim((string) $this->input('code'))),
            'name' => trim((string) $this->input('name')),
            'legal_name' => $this->nullableString('legal_name'),
            'service_tags' => $this->normalizedList($this->input('service_tags')),
            'allowed_tracking_hosts' => array_map(
                static fn (string $host): string => strtolower(rtrim($host, '.')),
                $this->normalizedList($this->input('allowed_tracking_hosts')),
            ),
            'website_url' => $this->nullableString('website_url'),
            'support_url' => $this->nullableString('support_url'),
            'tracking_page_url' => $this->nullableString('tracking_page_url'),
            'tracking_url_template' => $this->nullableString('tracking_url_template'),
            'connector_type' => $this->nullableString('connector_type'),
            'source_url' => $this->nullableString('source_url'),
            'verified_at' => $this->nullableString('verified_at'),
            'notes' => $this->nullableString('notes'),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var ShippingCarrier|null $carrier */
        $carrier = $this->route('shippingCarrier');

        return [
            'code' => [
                'required',
                'string',
                'max:64',
                'regex:/^[a-z0-9][a-z0-9_-]*$/',
                Rule::unique('shipping_carriers', 'code')->ignore($carrier?->id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'vendor_id' => ['nullable', 'integer', 'exists:vendors,id'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'lifecycle_state' => ['required', Rule::in(array_keys(ShippingCarrier::lifecycleOptions()))],
            'sort_order' => ['required', 'integer', 'min:0', 'max:10000'],
            'service_tags' => ['nullable', 'array', 'max:20'],
            'service_tags.*' => ['string', 'max:64', 'distinct', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'website_url' => ['required', 'string', 'max:2048', $this->httpsUrlRule()],
            'support_url' => ['nullable', 'string', 'max:2048', $this->httpsUrlRule()],
            'tracking_page_url' => [
                Rule::requiredIf($this->input('tracking_method') === ShippingCarrier::TRACKING_GENERIC_PAGE),
                'nullable',
                'string',
                'max:2048',
                $this->httpsUrlRule(),
            ],
            'tracking_method' => ['required', Rule::in(array_keys(ShippingCarrier::trackingMethodOptions()))],
            'tracking_url_template' => [
                Rule::requiredIf($this->input('tracking_method') === ShippingCarrier::TRACKING_TEMPLATE),
                'nullable',
                'string',
                'max:2048',
            ],
            'allowed_tracking_hosts' => ['required', 'array', 'min:1', 'max:20'],
            'allowed_tracking_hosts.*' => [
                'required',
                'string',
                'max:253',
                'distinct',
                'regex:/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',
            ],
            'link_visibility' => ['required', Rule::in(array_keys(ShippingCarrier::linkVisibilityOptions()))],
            'connector_type' => ['nullable', 'string', 'max:128', 'regex:/^[a-z0-9][a-z0-9._-]*$/'],
            'source_url' => ['required', 'string', 'max:2048', $this->httpsUrlRule()],
            'verification_state' => ['required', Rule::in(array_keys(ShippingCarrier::verificationOptions()))],
            'verified_at' => [
                Rule::requiredIf($this->input('verification_state') === ShippingCarrier::VERIFICATION_VERIFIED),
                'nullable',
                'date',
                'before_or_equal:today',
            ],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $resolver = app(ShippingTrackingLinkResolver::class);
            $allowedHosts = (array) $this->input('allowed_tracking_hosts', []);
            $trackingPageUrl = $this->input('tracking_page_url');
            $template = $this->input('tracking_url_template');

            if (
                is_string($trackingPageUrl)
                && $trackingPageUrl !== ''
                && ! $resolver->isAllowedUrl($trackingPageUrl, $allowedHosts)
            ) {
                $validator->errors()->add(
                    'tracking_page_url',
                    'The tracking page must use HTTPS and an allowed tracking host.',
                );
            }

            if (is_string($template) && $template !== '') {
                if (substr_count($template, ShippingTrackingLinkResolver::TRACKING_PLACEHOLDER) !== 1) {
                    $validator->errors()->add(
                        'tracking_url_template',
                        'The tracking template must contain exactly one {tracking_number} placeholder.',
                    );
                } elseif (! $resolver->isValidTemplate($template, $allowedHosts)) {
                    $validator->errors()->add(
                        'tracking_url_template',
                        'The tracking template must use HTTPS, contain no other placeholders, and use an allowed tracking host.',
                    );
                }
            }
        });
    }

    /** @return array<string, mixed> */
    public function carrierData(): array
    {
        return $this->safe()->only([
            'code',
            'name',
            'vendor_id',
            'legal_name',
            'lifecycle_state',
            'sort_order',
            'service_tags',
            'website_url',
            'support_url',
            'tracking_page_url',
            'tracking_method',
            'tracking_url_template',
            'allowed_tracking_hosts',
            'link_visibility',
            'connector_type',
            'source_url',
            'verification_state',
            'verified_at',
            'notes',
        ]);
    }

    private function httpsUrlRule(): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            if (! app(ShippingTrackingLinkResolver::class)->isHttpsUrl((string) $value)) {
                $fail('The :attribute must be a valid HTTPS URL without embedded credentials.');
            }
        };
    }

    /** @return array<int, string> */
    private function normalizedList(mixed $value): array
    {
        $values = is_array($value) ? $value : preg_split('/[\s,;]+/', (string) $value);

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $values ?: [],
        ))));
    }

    private function nullableString(string $key): ?string
    {
        $value = trim((string) $this->input($key, ''));

        return $value === '' ? null : $value;
    }
}
