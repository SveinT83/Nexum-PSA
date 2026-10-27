<?php

namespace App\Modules\CustomField\Actions;

use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Support\CustomFieldDefinitionInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Browser and API share a serial definition boundary and retain value rows on deletion. */
class SaveCustomFieldDefinition
{
    public function handle(Request $request, ?int $id = null, bool $web = false, bool $delete = false): CustomFieldDefinition
    {
        return DB::transaction(function () use ($request, $id, $web, $delete) {
            if (Schema::hasTable('custom_field_api_mutex')) {
                DB::table('custom_field_api_mutex')->where('id', 1)->lockForUpdate()->firstOrFail();
            }
            $definition = $id !== null ? CustomFieldDefinition::query()->lockForUpdate()->findOrFail($id) : null;
            if (! $web && $definition) {
                \App\Modules\CustomField\Support\CustomFieldRevision::check($request, $definition);
            }
            if ($delete) {
                if (array_diff(array_keys($request->all()), ['_token', '_method'])) {
                    throw \Illuminate\Validation\ValidationException::withMessages(['body' => 'Delete does not accept request fields.']);
                }
                $definition->forceFill(['active' => false])->save();
                $definition->delete();

                return $definition;
            }
            $data = CustomFieldDefinitionInput::validate($request, $definition, $web);
            $definition ??= new CustomFieldDefinition;
            $definition->fill($data)->save();

            return $definition->refresh();
        });
    }
}
