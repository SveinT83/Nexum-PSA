<?php

namespace App\Modules\Commercial\Actions;

use App\Models\Settings\CommonSetting;
use App\Modules\Commercial\Support\ClientTimebankQuickPolicy;
use App\Modules\Commercial\Support\CommercialInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Settings are pure to read; explicit reviewed updates serialize on the actual settings row. */
class TimebankPolicyWorkflow
{
    public static function revision(array $policy): string
    {
        return hash('sha256', json_encode(\App\Modules\Commercial\Support\CommercialRevision::stable($policy), JSON_THROW_ON_ERROR));
    }

    public function save(Request $request): array
    {
        return DB::transaction(function () use ($request) {
            CommonSetting::query()->where('type', 'commercial')->where('name', 'client_timebank_quick_policy')->lockForUpdate()->first();
            $policy = app(ClientTimebankQuickPolicy::class);
            $current = $policy->get();
            if ($request->is('api/*')) {
                abort_unless($request->header('If-Match'), 428, 'If-Match is required.');
                abort_unless(hash_equals(self::revision($current), trim($request->header('If-Match'), '"')), 409, 'The policy changed; review it again.');
            }
            $rules = ['quick_timebank_enabled' => 'sometimes|boolean', 'quick_timebank_require_remaining' => 'sometimes|boolean', 'quick_timebank_allow_overuse' => 'sometimes|boolean', 'quick_timebank_require_note' => 'sometimes|boolean', 'quick_timebank_max_minutes' => 'required|integer|min:1|max:1440'];
            $data = CommercialInput::validate($request, $rules, $request->is('api/*') ? array_merge($current, $request->all()) : $request->all());
            foreach (array_keys($rules) as $field) {
                if ($field !== 'quick_timebank_max_minutes' && ! $request->is('api/*')) {
                    $data[$field] = $request->boolean($field);
                }
            }

            return $policy->update($data);
        }, 3);
    }
}
