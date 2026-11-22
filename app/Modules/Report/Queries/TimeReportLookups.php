<?php

namespace App\Modules\Report\Queries;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\UserManagement\Queries\ReportEmployees;
use Illuminate\Support\Facades\Validator;

/** Lookup and report reads use the same permitted source records. No emails or private notes. */
class TimeReportLookups
{
    public function read(User $actor, string $kind, array $input): array
    {
        abort_unless(in_array($kind, ['employee', 'client', 'ticket', 'task'], true), 404);
        $data = Validator::make($input, ['q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'id' => ['nullable', 'integer', 'min:1']])->validate();
        if ($kind === 'employee' && empty($data['id'])) {
            return app(ReportEmployees::class)->page($data['q'] ?? '', (int) ($data['page'] ?? 1));
        }
        $query = match ($kind) {
            'employee' => app(ReportEmployees::class)->query(),
            'client' => Client::query(),
            'ticket' => app(\App\Modules\Ticket\Queries\ReportedTime::class)->records(),
            'task' => app(\App\Modules\Task\Queries\ReportedTime::class)->records($actor),
        };
        if (! empty($data['id'])) {
            $query->whereKey($data['id']);
        }
        $name = match ($kind) {
            'ticket' => 'subject', 'task' => 'title', default => 'name'
        };
        if (filled($data['q'] ?? '')) {
            $needle = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($data['q'])).'%';
            $query->where(function ($q) use ($needle, $name, $kind) {
                $q->whereRaw($name." LIKE ? ESCAPE '!'", [$needle]);
                if ($kind === 'client') {
                    $q->orWhereRaw("client_number LIKE ? ESCAPE '!'", [$needle]);
                } elseif ($kind === 'ticket') {
                    $q->orWhereRaw("ticket_key LIKE ? ESCAPE '!'", [$needle]);
                } elseif ($kind === 'task' && ctype_digit(trim(str_replace('%', '', $needle)))) {
                    $q->orWhere('id', (int) trim(str_replace('%', '', $needle)));
                }
            });
        }
        $fields = match ($kind) {
            'ticket' => ['id', 'ticket_key', 'subject', 'deleted_at'],
            'task' => ['id', 'title', 'deleted_at'], 'employee' => ['id', 'name', 'status'],
            default => ['id', 'name', 'client_number'],
        };
        $page = $query->orderBy($name)->orderBy('id')->paginate(50, $fields, 'page', (int) ($data['page'] ?? 1));

        return ['data' => $page->getCollection()->map(fn ($r) => ['id' => $r->id,
            'label' => match ($kind) {
                'ticket' => $r->ticket_key.' — '.$r->subject, 'task' => 'Task #'.$r->id.' — '.$r->title, default => $r->name
            },
            'hint' => match ($kind) {
                'client' => $r->client_number ?? '', 'employee' => $r->status === User::STATUS_ACTIVE ? '' : 'Inactive / former employee',
                default => $r->deleted_at ? 'Archived' : ''
            }])->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null]];
    }

    public function selected(User $actor, string $kind, ?int $id): ?array
    {
        return $id ? ($this->read($actor, $kind, ['id' => $id])['data'][0] ?? null) : null;
    }
}
