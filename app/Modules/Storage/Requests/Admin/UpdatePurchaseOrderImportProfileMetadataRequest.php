<?php

namespace App\Modules\Storage\Requests\Admin;

use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePurchaseOrderImportProfileMetadataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->isActive() ?? false)
            && ($this->user()?->can('storage.purchase_import_profile_manage') ?? false);
    }

    protected function prepareForValidation(): void
    {
        $scope = $this->input('matching_scope');
        if (is_string($scope)) {
            $scope = json_decode($scope, true);
        }

        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'slug' => is_string($this->input('slug')) ? trim($this->input('slug')) : $this->input('slug'),
            'description' => is_string($this->input('description'))
                ? trim($this->input('description'))
                : $this->input('description'),
            'matching_scope' => $scope,
            'reason' => is_string($this->input('reason')) ? trim($this->input('reason')) : $this->input('reason'),
        ]);
    }

    public function rules(): array
    {
        $profile = $this->route('purchaseOrderImportProfile');
        $profileId = $profile instanceof PurchaseOrderImportProfile ? $profile->getKey() : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/',
                Rule::unique('storage_purchase_order_import_profiles', 'slug')->ignore($profileId),
            ],
            'description' => ['nullable', 'string', 'max:2000'],
            'matching_scope' => ['required', 'array'],
            'reason' => ['required', 'string', 'min:5', 'max:245'],
        ];
    }
}
