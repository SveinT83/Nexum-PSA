<?php

namespace App\Modules\Integration\Services\Tripletex;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Integration\Exceptions\TripletexException;
use Illuminate\Support\Facades\Validator;

/** Explicit field boundary shared by prefill and reconciliation; contacts are not profile fields. */
class CustomerProfileData
{
    public const FIELDS = ['billing_email', 'address', 'co_address', 'zip', 'city', 'country'];

    public function addressKind(array $customer): string
    {
        $physical = $customer['physicalAddress'] ?? [];
        foreach (['addressLine1', 'addressLine2', 'postalCode', 'city'] as $key) {
            if (filled($physical[$key] ?? null)) {
                return 'physicalAddress';
            }
        }

        return ! empty($customer['postalAddress']) ? 'postalAddress' : 'physicalAddress';
    }

    public function remote(array $customer, string $kind): array
    {
        if (! in_array($kind, ['physicalAddress', 'postalAddress'], true)
            || ! array_key_exists('invoiceEmail', $customer) || ! array_key_exists($kind, $customer)) {
            throw new TripletexException('incomplete_customer_profile');
        }
        $address = $customer[$kind] ?? [];
        if (! is_array($address)) {
            throw new TripletexException('invalid_customer_address');
        }
        $country = data_get($address, 'country.isoAlpha2Code', '');
        if (! empty($address['country']) && ! preg_match('/^[A-Z]{2}$/i', (string) $country)) {
            throw new TripletexException('incomplete_customer_country');
        }

        return $this->validated([
            'billing_email' => $customer['invoiceEmail'] ?? '',
            'address' => $address['addressLine1'] ?? '', 'co_address' => $address['addressLine2'] ?? '',
            'zip' => $address['postalCode'] ?? '', 'city' => $address['city'] ?? '', 'country' => strtoupper((string) $country),
        ]);
    }

    public function local(Client $client, ClientSite $site, TripletexClient $api): array
    {
        $country = trim((string) $site->country);
        if ($country !== '') {
            $country = $api->country($country)['isoAlpha2Code'];
        }

        return $this->validated([
            'billing_email' => $client->billing_email ?? '', 'address' => $site->address ?? '',
            'co_address' => $site->co_address ?? '', 'zip' => $site->zip ?? '',
            'city' => $site->city ?? '', 'country' => $country,
        ]);
    }

    public function validated(array $values): array
    {
        $clean = [];
        foreach (self::FIELDS as $field) {
            if (! is_string($values[$field] ?? null)) {
                throw new TripletexException('invalid_customer_profile');
            }
            $clean[$field] = trim($values[$field]);
        }
        $rules = ['billing_email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'], 'co_address' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'], 'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'size:2']];
        if (Validator::make($clean, $rules)->fails()) {
            throw new TripletexException('invalid_customer_profile');
        }

        return $clean;
    }

    public function addressPayload(array $values, TripletexClient $api, array $current = []): array
    {
        $address = ['addressLine1' => $values['address'], 'addressLine2' => $values['co_address'],
            'postalCode' => $values['zip'], 'city' => $values['city'],
            'country' => $values['country'] === '' ? null : ['id' => $api->country($values['country'])['id']]];
        // Existing address identity/version protect nested updates without replacing other address fields.
        foreach (['id', 'version'] as $key) {
            if (isset($current[$key])) {
                $address[$key] = $current[$key];
            }
        }

        return $address;
    }

    public function prefill(array $customer): array
    {
        $values = $this->remote($customer, $this->addressKind($customer));
        $email = trim((string) ($customer['email'] ?? ''));
        // Never turn an invoice recipient or an obvious invoice inbox into the primary contact.
        if (strcasecmp($email, $values['billing_email']) === 0
            || preg_match('/(^|[.@+_-])(faktura|invoice|billing|accounts)([.@+_-]|$)/i', $email)
            || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = '';
        }

        return ['billing_email' => $values['billing_email'], 'site_name' => $values['address'],
            'site_address' => $values['address'], 'site_co_address' => $values['co_address'],
            'site_zip' => $values['zip'], 'site_city' => $values['city'], 'site_country' => $values['country'],
            'user_email' => $email, 'user_phone' => (string) (($customer['phoneNumberMobile'] ?? '') ?: ($customer['phoneNumber'] ?? '')),
            'user_name' => ''];
    }
}
