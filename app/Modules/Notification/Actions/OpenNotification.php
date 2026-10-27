<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use App\Modules\Notification\Notifications\WorkdayReminderNotification;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;

/** Resolve navigation through the current native route; source reads remain separate. */
class OpenNotification
{
    public function handle(User $user, string $id, bool $portal = false): array
    {
        $notification = app(ManageNotificationReads::class)->find($user, $id, $portal);
        $target = null;
        if ($notification->type === WorkdayReminderNotification::class) {
            abort_if($user->currentAccessToken() && ! $user->tokenCan('workday-reminders.write'), 403);
            $pending = collect(app(\App\Modules\Workday\Actions\WorkdayReminders::class)->pending($user))
                ->firstWhere('notification_id', $id);
            abort_unless($pending, 404);
            $target = $pending['url'];
        } elseif ($notification->type === InboundEmailRoutedNotification::class) {
            $target = $this->currentInboundSourceUrl($notification);
        }
        $target = $this->safeTarget($user, $target ?? ($notification->data['url'] ?? null), $portal);
        $acknowledged = $notification->type !== InboundEmailRoutedNotification::class;
        if ($acknowledged) {
            $notification->markAsRead();
        }

        return ['notification' => app(ManageNotificationReads::class)->serialize($notification->fresh(), $portal),
            'target_url' => $target, 'acknowledged' => $acknowledged,
            'source_read_required' => ! $acknowledged];
    }

    public function safeTarget(User $user, mixed $url, bool $portal): string
    {
        $fallback = route($portal ? 'customer-portal.dashboard' : 'tech.profile.notifications', [], false);
        if (! is_string($url) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return $fallback;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return $fallback;
        }
        if (isset($parts['host'])) {
            $base = parse_url(config('app.url'));
            if (($parts['scheme'] ?? null) !== ($base['scheme'] ?? null)
                || $parts['host'] !== ($base['host'] ?? null)
                || ($parts['port'] ?? null) !== ($base['port'] ?? null)) {
                return $fallback;
            }
        } elseif (! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return $fallback;
        }
        $path = $parts['path'] ?? '/';
        if ($portal && ! ($path === '/portal' || str_starts_with($path, '/portal/'))) {
            return $fallback;
        }
        try {
            $route = Route::getRoutes()->match(Request::create($path, 'GET'));
            if ($portal) {
                $context = request()->attributes->get('customerPortalContext')
                    ?? app(\App\Modules\CustomerPortal\Support\CustomerPortalContextResolver::class)->resolveForUser($user);
                if (! $context) {
                    return $fallback;
                }
                $probe = Request::create($path, 'GET');
                $route->bind($probe);
                app('router')->substituteBindings($route);
                app('router')->substituteImplicitBindings($route);
                $models = array_filter($route->parameters(), fn ($v) => $v instanceof \Illuminate\Database\Eloquent\Model);
                if ($models) {
                    $authorized = false;
                    foreach ((new \ReflectionMethod($route->getControllerClass(), $route->getActionMethod()))->getParameters() as $parameter) {
                        $type = $parameter->getType();
                        if (! $type instanceof \ReflectionNamedType || $type->isBuiltin()
                            || ! method_exists($type->getName(), 'canView')) {
                            continue;
                        }
                        foreach ($models as $model) {
                            if (app($type->getName())->canView($context, $model)) {
                                $authorized = true;
                            }
                        }
                    }
                    if (! $authorized) {
                        return $fallback;
                    }
                }
            }
            if (! $portal) {
                $permission = app(\App\Http\Middleware\EnforceTechRoutePermission::class);
                // Evaluate the same route permission without running its controller or read effects.
                $probe = Request::create($path, 'GET');
                $probe->setUserResolver(fn () => $user);
                $probe->setRouteResolver(fn () => $route);
                $permission->handle($probe, fn () => response(''));
            }
        } catch (\Throwable) {
            return $fallback;
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function currentInboundSourceUrl(DatabaseNotification $notification): ?string
    {
        $data = $notification->data;
        $emailMessageId = (int) ($data['email_message_id'] ?? 0);

        if ($emailMessageId > 0) {
            $email = EmailMessage::query()->find($emailMessageId);

            if ($email?->ticket_id) {
                $ticket = Ticket::query()->find($email->ticket_id);

                return $ticket ? route('tech.tickets.show', $ticket, false) : null;
            }

            if ($email) {
                return route('tech.inbox.show', $email, false);
            }
        }

        $ticketId = (int) ($data['ticket_id'] ?? 0);

        if ($ticketId > 0 && $ticket = Ticket::query()->find($ticketId)) {
            return route('tech.tickets.show', $ticket, false);
        }

        return null;
    }
}
