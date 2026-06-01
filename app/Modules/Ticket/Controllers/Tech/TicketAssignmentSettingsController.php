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
        $profile = $this->profileFor($request->user());
        $this->updateProfile($request, $profile);

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
        $data = $request->validate([
            'is_assignable' => 'nullable|boolean',
            'max_open_tickets' => 'required|integer|min:1|max:500',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:tags,id',
            'notes' => 'nullable|string|max:5000',
        ]);

        $profile->update([
            'is_assignable' => (bool) ($data['is_assignable'] ?? false),
            'max_open_tickets' => (int) $data['max_open_tickets'],
            'notes' => $data['notes'] ?? null,
        ]);

        // Matching signals are stored as explicit pivots so assignment scoring can query them efficiently.
        $profile->categories()->sync($data['category_ids'] ?? []);
        $profile->tags()->sync($data['tag_ids'] ?? []);

        // Ticket assignment notes stay in Ticket. General profile notes belong
        // to User Management and are edited from the unified profile page.
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
