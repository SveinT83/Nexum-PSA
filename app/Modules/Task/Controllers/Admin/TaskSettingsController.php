<?php

namespace App\Modules\Task\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Task\Support\TaskSettings;
use App\Modules\Ticket\Models\TicketPriority;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskSettingsController extends Controller
{
    public function edit(EnsureTaskDefaults $defaults, TaskSettings $settings)
    {
        $defaults->handle();

        return view('task::Admin.Settings.edit', [
            'settings' => $settings->get(),
            'statuses' => TaskStatus::query()->active()->orderBy('sort_order')->get(),
            'priorities' => TicketPriority::query()->where('is_active', true)->orderBy('sort_order')->orderBy('level')->get(),
            'defaultStatusId' => TaskStatus::query()->default()->value('id'),
        ]);
    }

    public function update(Request $request, TaskSettings $settings)
    {
        $validated = $request->validate([
            'default_status_id' => ['required', 'integer', Rule::exists('task_statuses', 'id')->where('is_active', true)],
            'default_priority_id' => ['nullable', 'integer', Rule::exists('ticket_priorities', 'id')->where('is_active', true)],
            'default_estimated_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'],
        ]);

        $settings->updateDefaultStatus((int) $validated['default_status_id']);
        $settings->update([
            'default_priority_id' => $validated['default_priority_id'] ?? null,
            'default_estimated_minutes' => $validated['default_estimated_minutes'] ?? null,
        ]);

        return redirect()
            ->route('tech.admin.settings.tasks')
            ->with('success', 'Task settings were updated.');
    }
}
