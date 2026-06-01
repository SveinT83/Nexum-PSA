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

class TechnicianProfileAdminController extends Controller
{
    public function index(): View
    {
        $profiles = TicketAssignmentSetting::with(['user', 'categories', 'tags'])
            ->orderBy('user_id')
            ->get();

        return view('ticket::Admin.TechnicianProfiles.index', [
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
        return view('ticket::Admin.TechnicianProfiles.edit', $this->viewData($profile->load(['user', 'categories', 'tags'])));
    }

    public function update(Request $request, TicketAssignmentSetting $profile): RedirectResponse
    {
        $this->updateProfile($request, $profile);

        return redirect()->route('tech.admin.settings.tickets.technicians')
            ->with('success', 'Ticket assignment settings updated.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:' . (new User())->getTable() . ',id|unique:ticket_assignment_settings,user_id',
        ]);

        TicketAssignmentSetting::create([
            'user_id' => $data['user_id'],
            'is_assignable' => true,
            'max_open_tickets' => 10,
        ]);

        return back()->with('success', 'Ticket assignment settings created.');
    }

    private function updateProfile(Request $request, TicketAssignmentSetting $profile): void
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

        $profile->categories()->sync($data['category_ids'] ?? []);
        $profile->tags()->sync($data['tag_ids'] ?? []);
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
