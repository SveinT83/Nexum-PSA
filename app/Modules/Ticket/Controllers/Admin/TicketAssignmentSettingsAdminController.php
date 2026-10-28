<?php

namespace App\Modules\Ticket\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAssignmentSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TicketAssignmentSettingsAdminController extends Controller
{
    public function index(): View
    {
        $profiles = TicketAssignmentSetting::with(['user', 'categories', 'tags'])
            ->orderBy('user_id')
            ->get();

        return view('ticket::Admin.TicketAssignmentSettings.index', [
            'profiles' => $profiles,
            'techniciansWithoutProfiles' => $this->techniciansWithoutProfiles(),
            'openTicketCounts' => Ticket::query()
                ->whereNotNull('owner_id')
                ->whereHas('status', fn ($query) => $query->where('is_closed', false))
                ->selectRaw('owner_id, count(*) as open_count')
                ->groupBy('owner_id')
                ->pluck('open_count', 'owner_id'),
        ]);
    }

    public function edit(TicketAssignmentSetting $profile): View
    {
        return view('ticket::Admin.TicketAssignmentSettings.edit', $this->viewData($profile->load(['user', 'categories', 'tags'])));
    }

    public function update(Request $request, TicketAssignmentSetting $profile): RedirectResponse
    {
        $this->updateProfile($request, $profile);

        return redirect()->route('tech.admin.settings.tickets.technicians')
            ->with('success', 'Ticket assignment settings updated.');
    }

    public function store(Request $request): RedirectResponse
    {
        app(\App\Modules\Ticket\Services\TicketAssignmentProfileEditor::class)->create($request);
        return back()->with('success', 'Ticket assignment settings created.');
    }

    private function updateProfile(Request $request, TicketAssignmentSetting $profile): void
    {
        app(\App\Modules\Ticket\Services\TicketAssignmentProfileEditor::class)->update($request, $profile);
    }


    private function viewData(TicketAssignmentSetting $profile): array
    {
        return [
            'profile' => $profile,
            'categories' => Category::forTickets()->active()->orderBy('name')->get(),
            'tags' => Tag::where('active', true)->orderBy('name')->get(),
        ];
    }

    private function techniciansWithoutProfiles()
    {
        $profileUserIds = TicketAssignmentSetting::pluck('user_id');

        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotIn('id', $profileUserIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
