<?php

namespace App\Modules\Notification\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebPushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isActive() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:500', 'url:https'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => [
                'required',
                'string',
                'min:20',
                'max:255',
                'regex:/^[A-Za-z0-9_-]+={0,2}$/',
            ],
            'keys.auth' => [
                'required',
                'string',
                'min:8',
                'max:255',
                'regex:/^[A-Za-z0-9_-]+={0,2}$/',
            ],
            'content_encoding' => ['nullable', 'string', Rule::in(['aes128gcm', 'aesgcm'])],
        ];
    }
}
