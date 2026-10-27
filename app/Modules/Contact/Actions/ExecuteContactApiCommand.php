<?php

namespace App\Modules\Contact\Actions;

use App\Modules\Contact\Models\ContactApiCommand;
use App\Modules\Contact\Support\ContactApiAccess;
use App\Modules\Contact\Support\ContactApiState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Serialize local writes with their receipts; risky writes require a fresh preview digest. */
class ExecuteContactApiCommand
{
    public function handle(Request $request, Closure $next)
    {
        $d = $request->route()->defaults;
        $allowed = $d['contact_fields'] ?? [];
        if ($unknown = array_diff(array_keys($request->all()), $allowed)) {
            throw ValidationException::withMessages(['payload' => 'Unsupported fields: '.implode(', ', $unknown)]);
        }
        $preview = ($d['contact_preview'] ?? false) || $request->boolean('dry_run')
            || (($d['contact_bulk_preview_default'] ?? false) && ! $request->has('dry_run'));
        if ($request->isMethod('get') || $preview) {
            $response = $next($request);
            $response->headers->set('ETag', $preview ? ContactApiState::previewTag($request) : ContactApiState::revision());

            return $response;
        }
        $risky = ($d['contact_risky'] ?? false) || $request->boolean('send_customer_portal_invitation');
        $key = $request->header('Idempotency-Key');
        abort_if($risky && ! $key, 428, 'Idempotency-Key is required.');
        abort_if($key !== null && (strlen($key) < 8 || strlen($key) > 200), 422, 'Idempotency-Key must contain 8 to 200 characters.');
        abort_if($risky && ! $request->hasHeader('If-Match'), 428, 'Preview and send its ETag as If-Match.');
        $actor = $request->user();
        $tokenHash = ContactApiAccess::tokenHash($request);
        $keyHash = hash('sha256', $key ?? (string) Str::uuid());
        $body = $request->all();
        ksort($body);
        $hash = hash('sha256', json_encode([$request->method(), $request->path(), $body, $request->header('If-Match')], JSON_THROW_ON_ERROR));
        $permissions = $d['contact_permissions'] ?? [];
        $abilities = $d['contact_abilities'] ?? [];
        if ($request->boolean('send_customer_portal_invitation')) {
            $permissions[] = 'customer_portal.invite';
            $abilities[] = 'contacts.portal_invite';
        }
        if ($request->boolean('delete_if_orphan')) {
            $permissions[] = 'contact.delete';
        }
        try {
            [$command, $replayed] = DB::transaction(function () use ($request, $next, $actor, $tokenHash, $keyHash, $hash, $permissions, $abilities, $risky) {
                abort_unless(DB::table('contact_api_mutex')->where('id', 1)->lockForUpdate()->first(), 503, 'Contact API migration is required.');
                $old = ContactApiCommand::where('actor_id', $actor->id)->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
                if ($old) {
                    abort_unless(hash_equals($old->request_hash, $hash), 409, 'Idempotency-Key was already used for another request.');
                    ContactApiAccess::authorize($request, $old->result['permissions'], $old->result['abilities']);

                    return [$old, true];
                }
                // Lock identity and scope rows also written by the native browser workflow.
                $etag = ContactApiState::revision(true);
                foreach ($request->route()->parameters() as $value) {
                    if ($value instanceof \Illuminate\Database\Eloquent\Model) {
                        $value->refresh();
                    }
                }
                if ($request->hasHeader('If-Match')) {
                    abort_unless(hash_equals($risky ? ContactApiState::previewTag($request, $etag) : $etag, $request->header('If-Match')), 412, 'Contact state changed; preview again.');
                }
                if ($request->boolean('send_customer_portal_invitation')) {
                    abort_unless(config('contact_api.portal_invitation_execution_enabled'), 409, 'Contact API invitation execution is disabled; preview is available.');
                }
                $response = $next($request);
                if ($response->getStatusCode() >= 400) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
                }
                $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
                $command = ContactApiCommand::create([
                    'id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'token_hash' => $tokenHash,
                    'key_hash' => $keyHash, 'operation' => $request->route()->getName(), 'request_hash' => $hash,
                    'state' => 'completed', 'result' => ['permissions' => $permissions, 'abilities' => $abilities,
                        'status' => $response->getStatusCode(), 'body' => $body, 'etag' => ContactApiState::revision()],
                ]);

                return [$command, false];
            });
        } catch (\Throwable $error) {
            // A queue callback may fail after commit. Keep a durable unknown outcome; never resend.
            $saved = ContactApiCommand::where('actor_id', $actor->id)->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
            if (! $saved || ! hash_equals($saved->request_hash, $hash)
                || $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $error instanceof ValidationException) {
                throw $error;
            }
            $saved->state = 'unknown';
            $result = $saved->result;
            $result['status'] = 202;
            $result['body'] = ['state' => 'unknown', 'reconciliation_required' => true];
            $saved->result = $result;
            $saved->save();
            [$command, $replayed] = [$saved, false];
        }
        $body = $command->result['body'];
        $body['operation_id'] = $command->id;
        $body['replayed'] = $replayed;

        return response()->json($body, $command->result['status'])
            ->header('X-Operation-Id', $command->id)->header('ETag', $command->result['etag']);
    }
}
