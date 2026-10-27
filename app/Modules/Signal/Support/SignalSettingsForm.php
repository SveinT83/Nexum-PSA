<?php

namespace App\Modules\Signal\Support;

use App\Modules\Integration\Models\AiAgent;
use Illuminate\Http\Request;

class SignalSettingsForm
{
    /** Browser and API share normalization and semantic validation. */
    public function validated(Request $request): array
    {
        $validated = $request->validate([
            'ai_classification_enabled' => ['sometimes', 'boolean'],
            'ai_min_confidence' => ['required', 'integer', 'min:0', 'max:100'],
            'ai_source_domains' => is_array($request->input('ai_source_domains')) ? ['nullable', 'array', 'max:100'] : ['nullable', 'string', 'max:2000'],
            'ai_allowed_signal_types' => is_array($request->input('ai_allowed_signal_types')) ? ['nullable', 'array', 'max:100'] : ['nullable', 'string', 'max:4000'],
            'ai_stop_ticket_routing_types' => is_array($request->input('ai_stop_ticket_routing_types')) ? ['nullable', 'array', 'max:100'] : ['nullable', 'string', 'max:4000'],
            'ai_classification_prompt' => ['nullable', 'string', 'max:12000'],
        ]);

        $validated['ai_classification_enabled'] = $request->boolean('ai_classification_enabled');
        $validated['ai_source_domains'] = is_array($validated['ai_source_domains'] ?? null) ? $validated['ai_source_domains'] : $this->splitList($validated['ai_source_domains'] ?? '');
        $validated['ai_allowed_signal_types'] = is_array($validated['ai_allowed_signal_types'] ?? null) ? $validated['ai_allowed_signal_types'] : $this->splitList($validated['ai_allowed_signal_types'] ?? '');
        $validated['ai_stop_ticket_routing_types'] = is_array($validated['ai_stop_ticket_routing_types'] ?? null) ? $validated['ai_stop_ticket_routing_types'] : $this->splitList($validated['ai_stop_ticket_routing_types'] ?? '');

        foreach (['ai_source_domains', 'ai_allowed_signal_types', 'ai_stop_ticket_routing_types'] as $field) {
            validator([$field => $validated[$field]], [$field.'.*' => 'string|max:100'])->validate();
        }

        return $validated;
    }

    public function signalAgent(): ?AiAgent
    {
        return AiAgent::query()
            ->with('provider')
            ->where('is_active', true)
            ->whereHas('provider', fn ($query) => $query->where('status', 'active'))
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->first(fn (AiAgent $agent): bool => in_array('signal', $agent->default_domains ?? [], true));
    }

    private function splitList(string $value): array
    {
        return array_values(array_filter(array_map(
            fn (string $item): string => trim($item),
            preg_split('/[\r\n,]+/', $value) ?: [],
        )));
    }
}
