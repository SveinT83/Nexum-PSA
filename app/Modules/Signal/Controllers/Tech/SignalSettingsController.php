<?php

namespace App\Modules\Signal\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Signal\Actions\EnsureSignalDefaults;
use App\Modules\Signal\Support\SignalSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SignalSettingsController extends Controller
{
    public function edit(EnsureSignalDefaults $defaults, SignalSettings $settings): View
    {
        $defaults->handle();

        return view('signal::Tech.settings', [
            'settings' => $settings->get(),
            'settingsSupport' => $settings,
            'agent' => $this->signalAgent(),
        ]);
    }

    public function update(Request $request, SignalSettings $settings): RedirectResponse
    {
        $validated = $request->validate([
            'ai_min_confidence' => ['required', 'integer', 'min:0', 'max:100'],
            'ai_source_domains' => ['nullable', 'string', 'max:2000'],
            'ai_allowed_signal_types' => ['nullable', 'string', 'max:4000'],
            'ai_stop_ticket_routing_types' => ['nullable', 'string', 'max:4000'],
            'ai_classification_prompt' => ['nullable', 'string', 'max:12000'],
        ]);

        $validated['ai_classification_enabled'] = $request->boolean('ai_classification_enabled');
        $validated['ai_source_domains'] = $this->splitList($validated['ai_source_domains'] ?? '');
        $validated['ai_allowed_signal_types'] = $this->splitList($validated['ai_allowed_signal_types'] ?? '');
        $validated['ai_stop_ticket_routing_types'] = $this->splitList($validated['ai_stop_ticket_routing_types'] ?? '');

        $settings->update($validated);

        return redirect()
            ->route('tech.admin.system.signals.settings.edit')
            ->with('status', 'Signal settings were updated.');
    }

    private function signalAgent(): ?AiAgent
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
