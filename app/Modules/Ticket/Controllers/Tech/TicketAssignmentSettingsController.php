<?php

namespace App\Modules\Ticket\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\TicketAssignmentSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TicketAssignmentSettingsController extends Controller
{
    public function edit(Request $request): View
    {
        return view('ticket::Tech.TicketAssignmentSettings.edit', $this->viewData(
            $this->profileFor($request->user())
        ));
    }

    public function update(Request $request): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketAssignmentProfileEditor::class)->saveOwn($request);

        return back()->with('success', 'Ticket assignment settings updated.');
    }

    protected function profileFor(User $user): TicketAssignmentSetting
    {
        return TicketAssignmentSetting::firstOrCreate(
            ['user_id' => $user->id],
            [
                'is_assignable' => true,
                'max_open_tickets' => 10,
            ]
        )->load(['categories', 'tags', 'user']);
    }

    protected function updateProfile(Request $request, TicketAssignmentSetting $profile): void
    {
        app(\App\Modules\Ticket\Services\TicketAssignmentProfileEditor::class)->update($request, $profile);
    }


    protected function viewData(TicketAssignmentSetting $profile): array
    {
        return [
            'profile' => $profile,
            'categories' => Category::forTickets()->active()->orderBy('name')->get(),
            'tags' => Tag::where('active', true)->orderBy('name')->get(),
        ];
    }
}
