<?php

namespace App\Modules\Ticket\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\TicketTechnicianProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TechnicianProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('ticket::Tech.TechnicianProfile.edit', $this->viewData(
            $this->profileFor($request->user())
        ));
    }

    public function update(Request $request): RedirectResponse
    {
        $profile = $this->profileFor($request->user());
        $this->updateProfile($request, $profile);

        return back()->with('success', 'Ticket technician profile updated.');
    }

    protected function profileFor(User $user): TicketTechnicianProfile
    {
        return TicketTechnicianProfile::firstOrCreate(
            ['user_id' => $user->id],
            [
                'is_assignable' => true,
                'max_open_tickets' => 10,
                'timezone' => config('app.timezone', 'UTC'),
                'working_hours' => $this->defaultWorkingHours(),
            ]
        )->load(['categories', 'tags', 'user']);
    }

    protected function updateProfile(Request $request, TicketTechnicianProfile $profile): void
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

        // Skills are stored as explicit pivots so assignment scoring can query them efficiently later.
        $profile->categories()->sync($data['category_ids'] ?? []);
        $profile->tags()->sync($data['tag_ids'] ?? []);
    }

    protected function viewData(TicketTechnicianProfile $profile): array
    {
        return [
            'profile' => $profile,
            'categories' => Category::forTickets()->active()->orderBy('name')->get(),
            'tags' => Tag::where('active', true)->orderBy('name')->get(),
            'workingHours' => array_replace_recursive($this->defaultWorkingHours(), $profile->working_hours ?? []),
        ];
    }

    protected function defaultWorkingHours(): array
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
}
