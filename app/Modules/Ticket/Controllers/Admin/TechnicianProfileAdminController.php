<?php

namespace App\Modules\Ticket\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketTechnicianProfile;
use App\Modules\UserManagement\Models\UserProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TechnicianProfileAdminController extends Controller
{
    public function index(): View
    {
        $profiles = TicketTechnicianProfile::with(['user', 'categories', 'tags'])
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

    public function edit(TicketTechnicianProfile $profile): View
    {
        return view('ticket::Admin.TechnicianProfiles.edit', $this->viewData($profile->load(['user', 'categories', 'tags'])));
    }

    public function update(Request $request, TicketTechnicianProfile $profile): RedirectResponse
    {
        $this->updateProfile($request, $profile);

        return redirect()->route('tech.admin.settings.tickets.technicians')
            ->with('success', 'Technician profile updated.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => 'required|integer|exists:' . (new User())->getTable() . ',id|unique:ticket_technician_profiles,user_id',
        ]);

        $userProfile = UserProfile::query()->where('user_id', $data['user_id'])->first();

        $profile = TicketTechnicianProfile::create([
            'user_id' => $data['user_id'],
            'is_assignable' => true,
            'max_open_tickets' => 10,
            'timezone' => $userProfile?->timezone ?? config('app.timezone', 'UTC'),
            'working_hours' => $userProfile?->working_hours ?? $this->defaultWorkingHours(),
        ]);

        $this->mirrorToUserProfile($profile);

        return back()->with('success', 'Technician profile created.');
    }

    private function updateProfile(Request $request, TicketTechnicianProfile $profile): void
    {
        $data = $request->validate([
            'is_assignable' => 'nullable|boolean',
            'max_open_tickets' => 'required|integer|min:1|max:500',
            'timezone' => 'required|string|max:80',
            'working_hours' => 'nullable|array',
            'working_hours.*.enabled' => 'nullable|boolean',
            'working_hours.*.start' => 'nullable|date_format:H:i',
            'working_hours.*.end' => 'nullable|date_format:H:i',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'integer|exists:categories,id',
            'tag_ids' => 'nullable|array',
            'tag_ids.*' => 'integer|exists:tags,id',
            'notes' => 'nullable|string|max:5000',
        ]);

        $profile->update([
            'is_assignable' => (bool) ($data['is_assignable'] ?? false),
            'max_open_tickets' => (int) $data['max_open_tickets'],
            'timezone' => $data['timezone'],
            'working_hours' => $this->normalizedWorkingHours($data['working_hours'] ?? []),
            'notes' => $data['notes'] ?? null,
        ]);

        $profile->categories()->sync($data['category_ids'] ?? []);
        $profile->tags()->sync($data['tag_ids'] ?? []);

        $this->mirrorToUserProfile($profile);
    }

    private function viewData(TicketTechnicianProfile $profile): array
    {
        return [
            'profile' => $profile,
            'categories' => Category::forTickets()->active()->orderBy('name')->get(),
            'tags' => Tag::where('active', true)->orderBy('name')->get(),
            'workingHours' => array_replace_recursive($this->defaultWorkingHours(), $profile->working_hours ?? []),
        ];
    }

    private function defaultWorkingHours(): array
    {
        return collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn (string $day) => [$day => [
                'enabled' => in_array($day, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], true),
                'start' => '08:00',
                'end' => '16:00',
            ]])
            ->all();
    }

    private function normalizedWorkingHours(array $workingHours): array
    {
        return collect($this->defaultWorkingHours())
            ->mapWithKeys(function (array $defaults, string $day) use ($workingHours) {
                $submitted = $workingHours[$day] ?? [];

                return [$day => [
                    'enabled' => (bool) ($submitted['enabled'] ?? false),
                    'start' => $submitted['start'] ?? $defaults['start'],
                    'end' => $submitted['end'] ?? $defaults['end'],
                ]];
            })
            ->all();
    }

    private function techniciansWithoutProfiles()
    {
        $profileUserIds = TicketTechnicianProfile::pluck('user_id');

        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereNotIn('id', $profileUserIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }

    private function mirrorToUserProfile(TicketTechnicianProfile $profile): void
    {
        UserProfile::query()->updateOrCreate(
            ['user_id' => $profile->user_id],
            [
                'timezone' => $profile->timezone,
                'working_hours' => $profile->working_hours,
                'profile_notes' => $profile->notes,
                'migrated_from_ticket_technician_profile_id' => $profile->id,
                'migrated_at' => now(),
            ]
        );
    }
}
