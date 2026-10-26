<?php

namespace App\Modules\Risk\Support;

use App\Models\Clients\Client;
use App\Models\Risk\RiskAssessment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Strict API inputs; merge defaults and omitted values only after validation. */
final class RiskPayload
{
    public const ASSESSMENT = ['title', 'description', 'scope', 'client_id', 'status'];

    public const ITEM = ['title', 'description', 'recommended_actions', 'conclusion', 'category_id', 'likelihood', 'impact', 'status', 'next_review_at'];

    public static function assessment(Request $r, bool $create, ?RiskAssessment $current = null): array
    {
        RiskInput::only($r, $create ? self::ASSESSMENT : array_diff(self::ASSESSMENT, ['status']));
        $data = $r->validate(['title' => [$create ? 'required' : 'sometimes', 'string', 'max:255'], 'description' => ['sometimes', 'nullable', 'string'], 'scope' => ['sometimes', Rule::in(['internal', 'client'])], 'client_id' => ['sometimes', 'nullable', 'integer', 'exists:clients,id'], 'status' => ['sometimes', Rule::in(array_keys(RiskSettings::ASSESSMENT_STATUS_OPTIONS))]]);
        $prior = $create ? app(RiskSettings::class)->assessmentDefaults() : ['title' => $current->title, 'description' => $current->description, 'scope' => $current->client_id ? 'client' : 'internal', 'client_id' => $current->client_id];
        if ($create && ! array_key_exists('scope', $data) && ! empty($data['client_id'])) {
            $data['scope'] = 'client';
        }
        $data = array_replace($prior, $data);
        if ($data['scope'] === 'client') {
            if (empty($data['client_id']) || ! Client::whereKey($data['client_id'])->where('active', true)->exists()) {
                throw ValidationException::withMessages(['client_id' => 'Select an active Client for client scope.']);
            }
        } elseif (! empty($data['client_id'])) {
            throw ValidationException::withMessages(['client_id' => 'Internal scope must not include a Client.']);
        }

        return $data;
    }

    public static function item(Request $r, bool $create): array
    {
        RiskInput::only($r, self::ITEM);

        return $r->validate(['title' => [$create ? 'required' : 'sometimes', 'string', 'max:255'], 'description' => ['sometimes', 'nullable', 'string'], 'recommended_actions' => ['sometimes', 'nullable', 'string'], 'conclusion' => ['sometimes', 'nullable', 'string'], 'category_id' => ['sometimes', 'nullable', 'integer', 'exists:categories,id'], 'likelihood' => ['sometimes', 'integer', 'min:1', 'max:5'], 'impact' => ['sometimes', 'integer', 'min:1', 'max:5'], 'status' => ['sometimes', Rule::in(['open', 'mitigated', 'accepted'])], 'next_review_at' => ['sometimes', 'nullable', 'date']]);
    }

    public static function history(Request $r): array
    {
        RiskInput::only($r, ['note', 'status', 'likelihood', 'impact', 'next_review_at']);

        return $r->validate(['note' => ['required', 'string'], 'status' => ['required', Rule::in(['open', 'mitigated', 'accepted'])], 'likelihood' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'], 'impact' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5'], 'next_review_at' => ['sometimes', 'nullable', 'date']]);
    }

    public static function settings(array $input, bool $partial): array
    {
        $mode = $partial ? 'sometimes' : 'required';
        $rules = ['default_assessment_scope' => [$mode, Rule::in(array_keys(RiskSettings::ASSESSMENT_SCOPE_OPTIONS))], 'default_assessment_status' => [$mode, Rule::in(array_keys(RiskSettings::ASSESSMENT_STATUS_OPTIONS))], 'default_item_likelihood' => [$mode, 'integer', 'min:1', 'max:5'], 'default_item_impact' => [$mode, 'integer', 'min:1', 'max:5'], 'default_item_status' => [$mode, Rule::in(array_keys(RiskSettings::ITEM_STATUS_OPTIONS))], 'default_item_review_days' => [$mode, 'integer', 'min:1', 'max:3650']];
        if (array_diff(array_keys($input), array_keys($rules))) {
            throw ValidationException::withMessages(['input' => 'Unsupported Risk settings field.']);
        }

        return Validator::make($input, $rules)->validate();
    }
}
