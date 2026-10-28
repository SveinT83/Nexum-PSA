<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Integration\Support\DomainApiAudit;
use App\Modules\Ticket\Models\TicketAssignmentSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Ticket-owned skills/notes stay separate from User Management's general profile and working hours. */
class TicketAssignmentProfileEditor
{
    public function rules(): array
    {
        return [
            'is_assignable' => ['nullable', 'boolean'], 'max_open_tickets' => ['required', 'integer', 'min:1', 'max:500'],
            'category_ids' => ['nullable', 'array'], 'category_ids.*' => ['integer', 'exists:categories,id'],
            'tag_ids' => ['nullable', 'array'], 'tag_ids.*' => ['integer', 'exists:tags,id'], 'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function create(Request $request): TicketAssignmentSetting
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:'.(new \App\Models\Core\User)->getTable().',id', 'unique:ticket_assignment_settings,user_id']]);

        return DB::transaction(function () use ($request, $data) {
            $profile = TicketAssignmentSetting::create(['user_id' => $data['user_id'], 'is_assignable' => true, 'max_open_tickets' => 10]);
            app(DomainApiAudit::class)->record($request->user(), 'ticket', 'ticket.assignment.profile.created', $profile, [], $profile->attributesToArray(), ['profile_user_id' => (int) $profile->user_id]);

            return $profile->load(['user', 'categories', 'tags']);
        });
    }

    public function update(Request $request, TicketAssignmentSetting $profile): TicketAssignmentSetting
    {
        return $this->save($request, $request->validate($this->rules()), $profile);
    }

    public function saveOwn(Request $request): TicketAssignmentSetting
    {
        $data = $request->validate($this->rules());

        return DB::transaction(function () use ($request, $data) {
            $profile = TicketAssignmentSetting::firstOrCreate(['user_id' => $request->user()->id], ['is_assignable' => true, 'max_open_tickets' => 10]);

            return $this->save($request, $data, $profile, $profile->wasRecentlyCreated);
        });
    }

    private function save(Request $request, array $data, TicketAssignmentSetting $profile, bool $created = false): TicketAssignmentSetting
    {
        return DB::transaction(function () use ($request, $data, $profile, $created) {
            $profile = TicketAssignmentSetting::query()->lockForUpdate()->findOrFail($profile->id)->load(['categories', 'tags']);
            $before = $created ? [] : $profile->attributesToArray() + ['category_ids' => $profile->categories->modelKeys(), 'tag_ids' => $profile->tags->modelKeys()];
            $profile->update(['is_assignable' => (bool) ($data['is_assignable'] ?? false), 'max_open_tickets' => (int) $data['max_open_tickets'], 'notes' => $data['notes'] ?? null]);
            $profile->categories()->sync($data['category_ids'] ?? []);
            $profile->tags()->sync($data['tag_ids'] ?? []);
            $profile->load(['user', 'categories', 'tags']);
            $after = $profile->attributesToArray() + ['category_ids' => $profile->categories->modelKeys(), 'tag_ids' => $profile->tags->modelKeys()];
            app(DomainApiAudit::class)->record($request->user(), 'ticket', $created ? 'ticket.assignment.profile.created' : 'ticket.assignment.profile.updated', $profile, $before, $after, ['profile_user_id' => (int) $profile->user_id]);

            return $profile;
        });
    }
}
