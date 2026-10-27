<?php

namespace App\Modules\Marketing\Support;

use App\Models\Settings\CommonSetting;
use App\Modules\Marketing\Models\MarketingCampaign;
use App\Modules\Marketing\Models\MarketingList;
use Illuminate\Http\Request;

/** Stable revision includes child records that can change campaign/list decisions. */
class MarketingApiState
{
    public static function revision(mixed $data): string
    {
        $sort = function ($value) use (&$sort) {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sort, $value);
        };

        return '"'.hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR)).'"';
    }

    public static function etag(Request $request): ?string
    {
        $campaign = $request->route('campaign');
        $list = $request->route('list');
        if ($campaign instanceof MarketingCampaign) {
            $fresh = $campaign->fresh();
            if (! $fresh) {
                return null;
            }

            return self::revision([$fresh->getAttributes(), $fresh->emails()->orderBy('id')->get()->map->getAttributes()->all(),
                $fresh->lists()->orderBy('marketing_lists.id')->pluck('marketing_lists.id')->all(),
                $fresh->recipients()->orderBy('id')->get()->map->getAttributes()->all(),
                $fresh->contentSources()->orderBy('id')->get()->map->getAttributes()->all()]);
        }
        if ($list instanceof MarketingList) {
            $fresh = $list->fresh();

            return $fresh ? self::revision([$fresh->getAttributes(), $fresh->members()->orderBy('id')->get()->map->getAttributes()->all()]) : null;
        }
        if (str_contains($request->path(), '/settings')) {
            return self::revision(CommonSetting::where('type', 'marketing')->orderBy('id')->get()->map->getAttributes()->all());
        }

        return null;
    }
}
