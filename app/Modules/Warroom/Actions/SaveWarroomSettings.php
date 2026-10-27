<?php

namespace App\Modules\Warroom\Actions;

use App\Models\Core\User;
use App\Modules\Warroom\Models\WarroomApiCommand;
use App\Modules\Warroom\Support\WarroomAccess;
use App\Modules\Warroom\Support\WarroomSettings;
use App\Modules\Warroom\Support\WarroomSettingsInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** The permanent singleton serializes browser and API; settings and receipt commit together. */
class SaveWarroomSettings
{
    public function __construct(private WarroomSettings $settings) {}

    public function snapshot(): array
    {
        abort_unless(Schema::hasTable('warroom_api_mutex'), 503, 'The Warroom settings migration is required.');
        $settings = $this->settings->get();
        $generation = DB::table('warroom_api_mutex')->where('id', 1)->value('revision');
        abort_if($generation === null, 503, 'The Warroom settings lock is unavailable.');

        return ['settings' => $settings, 'revision' => '"'.hash('sha256', json_encode([$generation, $settings], JSON_THROW_ON_ERROR)).'"',
            'section_options' => WarroomSettings::SECTION_OPTIONS];
    }

    public function execute(User $actor, array $payload, ?string $revision, ?string $key = null, ?string $tokenHash = null): array
    {
        abort_unless(WarroomAccess::allows($actor, 'warroom.manage_settings'), 403);
        $validated = WarroomSettingsInput::validate($payload);
        abort_unless($revision, 428, 'Reload Warroom settings before saving; If-Match or form revision is required.');
        if ($tokenHash !== null) {
            abort_unless($key, 428, 'Idempotency-Key is required.');
            abort_unless(strlen($key) >= 8 && strlen($key) <= 200, 422, 'Idempotency-Key must contain 8 to 200 characters.');
        }
        $canonical = $validated;
        foreach ($canonical as $field => &$value) {
            if ($field !== 'enabled_sections') {
                $value = (int) $value;
            }
        }
        unset($value);
        ksort($canonical);
        $requestHash = hash('sha256', json_encode([$canonical, $revision], JSON_THROW_ON_ERROR));
        $keyHash = hash('sha256', $key ?? (string) Str::uuid());
        abort_unless(Schema::hasTable('warroom_api_mutex'), 503, 'The Warroom settings migration is required.');

        try {
            return DB::transaction(function () use ($actor, $canonical, $revision, $tokenHash, $keyHash, $requestHash) {
                abort_unless(DB::table('warroom_api_mutex')->where('id', 1)->lockForUpdate()->first(), 503, 'Warroom settings lock is unavailable.');
                if ($tokenHash !== null) {
                    $previous = WarroomApiCommand::where('actor_id', $actor->id)->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
                    if ($previous) {
                        abort_unless(hash_equals($previous->request_hash, $requestHash), 409, 'Idempotency-Key was used for another request.');

                        return $previous->result + ['operation_id' => $previous->id, 'replayed' => true];
                    }
                }
                abort_unless(hash_equals($this->snapshot()['revision'], $revision), 412, 'Warroom settings changed; reload before saving.');
                $this->settings->update($canonical);
                DB::table('warroom_api_mutex')->where('id', 1)->increment('revision');
                $result = $this->snapshot();
                $id = null;
                if ($tokenHash !== null) {
                    $id = (string) Str::uuid();
                    WarroomApiCommand::create(['id' => $id, 'actor_id' => $actor->id,
                        'token_hash' => $tokenHash, 'key_hash' => $keyHash,
                        'request_hash' => $requestHash, 'result' => $result]);
                }

                return $result + ['operation_id' => $id, 'replayed' => false];
            }, 3);
        } catch (\Illuminate\Database\QueryException|\PDOException $error) {
            abort(503, 'Warroom settings are unavailable; the transaction was not completed.');
        }
    }
}
