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
        $validated = $request->validate(\App\Modules\Task\Support\TaskSettingsPayload::rules());
        app(\App\Modules\Task\Actions\UpdateTaskSettings::class)->handle($validated, $request->user());

        return redirect()
            ->route('tech.admin.settings.tasks')
            ->with('success', 'Task settings were updated.');
    }
}
