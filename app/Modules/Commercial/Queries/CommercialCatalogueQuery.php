<?php

namespace App\Modules\Commercial\Queries;

use App\Modules\Commercial\Models\Cost;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\Packages\Package;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Commercial\Models\Terms\terms;
use App\Modules\Commercial\Models\TimeRate;
use Illuminate\Http\Request;

/** Explicit catalogue queries and bounded form lookup discovery. No setup or writes on reads. */
class CommercialCatalogueQuery
{
    public static function model(string $kind): string
    {
        return match ($kind) {
            'services' => Services::class,'packages' => Package::class,'costs' => Cost::class,'terms' => terms::class,'slas' => Sla::class,'time-rates' => TimeRate::class,'units' => Units::class,default => throw new \InvalidArgumentException('Unknown catalogue')
        };
    }

    public function listing(Request $request, string $kind)
    {
        $class = self::model($kind);
        $q = $class::query();
        $table = $q->getModel()->getTable();
        $sorts = match ($kind) {
            'services' => ['name', 'sku', 'price_ex_vat', 'price', 'vendor', 'source', 'billing_cycle', 'status', 'updated_at'],'packages' => ['name', 'description', 'status', 'updated_at', 'services'],'costs' => ['name', 'cost', 'recurrence', 'source', 'updated_at', 'vendor'],'slas' => ['name', 'low_firstResponse', 'low_onsite', 'medium_firstResponse', 'medium_onsite', 'high_firstResponse', 'high_onsite'],'time-rates' => ['name', 'code', 'sort_order', 'amount_ex_vat'],default => ['name', 'id', 'updated_at']
        };
        $request->validate(['sort' => ['sometimes', \Illuminate\Validation\Rule::in($sorts)], 'client_id' => 'sometimes|integer|min:1', 'vendor_id' => 'sometimes|integer|min:1', 'status' => 'sometimes|string|max:50', 'billing_cycle' => 'sometimes|string|max:50', 'availability_audience' => 'sometimes|in:all,business,private', 'orderable' => 'sometimes|in:0,1,true,false,yes,no', 'is_active' => 'sometimes|boolean', 'source' => 'sometimes|string|max:100', 'audience' => 'sometimes|in:all,business,private', 'recurrence' => 'sometimes|string|max:50']);
        if ($request->filled('q')) {
            $needle = '%'.trim($request->string('q')).'%';
            $q->where(function ($inner) use ($kind, $needle, $table) {
                $inner->where($table.'.name', 'like', $needle);
                foreach (match ($kind) {
                    'services' => ['sku', 'short_description', 'status'],'packages' => ['description', 'status'],'costs' => ['note', 'recurrence', 'source'],'terms' => ['content', 'type'],'slas' => ['description'],'time-rates' => ['code', 'description'],default => ['short', 'common_code']
                } as $field) {
                    $inner->orWhere($table.'.'.$field, 'like', $needle);
                }if ($kind === 'costs') {
                    $inner->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', $needle));
                }
            });
        }
        foreach (match ($kind) {
            'services' => ['status', 'billing_cycle', 'availability_audience', 'orderable', 'vendor_id', 'source'],'packages' => ['status'],'costs' => ['vendor_id', 'recurrence'],'time-rates' => ['is_active'],default => []
        } as $field) {
            if ($request->has($field)) {
                $q->where($table.'.'.$field, $field === 'orderable' ? in_array($request->input($field), [true, 1, '1', 'true', 'yes'], true) : $request->input($field));
            }
        }
        if ($kind === 'services' && $request->filled('audience')) {
            $q->where('availability_audience', $request->input('audience'));
        }
        $sort = $request->input('sort', $kind === 'time-rates' ? 'sort_order' : 'name');
        $dir = $request->input('direction', 'asc');
        if ($kind === 'services' && $sort === 'price') {
            $sort = 'price_ex_vat';
        }
        if ($kind === 'services' && $sort === 'vendor') {
            $q->orderBy(\App\Modules\Documentation\Models\Vendor::query()->select('name')->whereColumn('vendors.id', 'services.vendor_id'), $dir);
        } elseif ($kind === 'packages' && $sort === 'services') {
            $q->withCount('services')->orderBy('services_count', $dir);
        } elseif ($kind === 'costs' && $sort === 'vendor') {
            $q->leftJoin('vendors', $table.'.vendor_id', '=', 'vendors.id')->select($table.'.*')->orderBy('vendors.name', $dir);
        } else {
            $q->orderBy($table.'.'.$sort, $dir);
        }

        return $q->orderBy($table.'.id')->paginate($request->integer('per_page', 20));
    }

    public function lookups(string $owner): array
    {
        return match ($owner) {
            'services' => ['units', 'slas', 'costs', 'terms', 'time-rates', 'services', 'vendors'],'packages' => ['services', 'terms', 'units'],'costs' => ['units', 'vendors'],'contracts' => ['clients', 'slas', 'services', 'time-rates', 'offers', 'technicians'],'terms' => ['terms'],'slas' => ['slas'],'time-rates' => ['time-rates'],'units' => ['units'],default => []
        };
    }

    public function lookup(Request $request, string $owner, string $lookup)
    {
        abort_unless(in_array($lookup, $this->lookups($owner), true), 404);
        [$class,$fields] = match ($lookup) {
            'clients' => [\App\Models\Clients\Client::class, ['id', 'name', 'client_number']],
            'technicians' => [\App\Models\Core\User::class, ['id', 'name']],
            'vendors' => [\App\Modules\Documentation\Models\Vendor::class, ['id', 'name']],
            'offers' => [\App\Modules\Integration\Models\CloudFactory\Offer::class, ['id', 'name', 'service_id', 'integration_id', 'currency', 'sku', 'recurrence_term', 'billing_term', 'sell_enabled', 'excluded', 'deprecated', 'purchasable', 'cost', 'msrp', 'manual_sale_price', 'markup_percent']],
            'services' => [Services::class, ['id', 'name', 'sku', 'unitId', 'sla_id', 'status', 'availability_audience', 'orderable', 'price_ex_vat', 'price_currency', 'billing_cycle', 'timebank_enabled']],
            'costs' => [Cost::class, ['id', 'name', 'cost', 'currency', 'unitId', 'recurrence', 'vendor_id']],
            'terms' => [terms::class, ['id', 'name', 'type', 'current_version_id', 'origin', 'managed_externally']],
            'time-rates' => [TimeRate::class, ['id', 'name', 'code', 'amount_ex_vat', 'currency', 'unit', 'rate_type', 'is_active', 'applies_with_contract', 'is_customer_visible']],
            'slas' => [Sla::class, ['id', 'name', 'is_default']],
            'units' => [Units::class, ['id', 'name', 'short', 'common_code']],
        };
        $q = $class::query()->select($fields)->orderBy('id');
        if ($lookup === 'technicians') {
            $q = app(CommercialContractFormContext::class)->technicians()->select($fields)->orderBy('id');
        }
        if ($request->filled('q') && in_array('name', $fields, true)) {
            $q->where('name', 'like', '%'.$request->string('q').'%');
        }
        if ($lookup === 'offers') {
            $request->validate(['service_id' => 'required|integer|exists:services,id']);
            $q->where('service_id', $request->integer('service_id'))->whereHas('service', fn ($s) => $s->where('managed_externally', true)->whereColumn('services.source_integration_id', 'cloudfactory_offers.integration_id')->whereHas('sourceIntegration', fn ($i) => $i->where('status', 'active')));
        }

        return $q->paginate($request->integer('per_page', 50));
    }
}
