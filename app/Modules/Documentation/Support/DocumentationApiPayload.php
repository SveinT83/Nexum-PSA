<?php

namespace App\Modules\Documentation\Support;

use App\Models\Clients\ClientSite;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Documentation\Models\DocumentationTemplate;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Validation\ValidationException;

class DocumentationApiPayload
{
    public function __construct(private readonly ResolveWorkContext $workContexts)
    {
    }

    public function createPayload(array $data): array
    {
        $category = $this->categoryFromPayload($data);
        $template = $this->templateFromPayload($data, $category);
        $scope = $this->scopeFromPayload($data);
        $documentData = $this->documentData($data);
        $snapshot = $this->snapshotForData($template->fields ?? [], $documentData);

        return array_merge($scope, [
            'template_id' => $template->id,
            'category_id' => $category->id,
            'title' => $data['title'],
            'template_snapshot_json' => $snapshot,
            'data_json' => $documentData,
        ]);
    }

    public function updatePayload(Documentation $documentation, array $data): array
    {
        $category = array_key_exists('category_id', $data) || array_key_exists('category_slug', $data)
            ? $this->categoryFromPayload($data)
            : $documentation->category;

        if (! $category) {
            throw ValidationException::withMessages([
                'category_id' => 'A documentation category is required.',
            ]);
        }

        $template = array_key_exists('template_id', $data) || (int) $category->id !== (int) $documentation->category_id
            ? $this->templateFromPayload($data, $category)
            : $documentation->template;

        if (! $template) {
            throw ValidationException::withMessages([
                'template_id' => 'A documentation template is required.',
            ]);
        }

        $scope = $this->scopeFromPayload(array_merge([
            'client_id' => $documentation->client_id,
            'site_id' => $documentation->site_id,
        ], array_intersect_key($data, array_flip(['client_id', 'site_id']))));

        $documentData = $this->documentData($data, $documentation->data_json ?? []);
        $baseSnapshot = (int) $template->id === (int) $documentation->template_id
            ? ($documentation->template_snapshot_json ?? [])
            : ($template->fields ?? []);
        $snapshot = $this->snapshotForData($baseSnapshot, $documentData);

        return array_merge($scope, [
            'template_id' => $template->id,
            'category_id' => $category->id,
            'title' => $data['title'] ?? $documentation->title,
            'template_snapshot_json' => $snapshot,
            'data_json' => $documentData,
        ]);
    }

    public function categoryFromPayload(array $data): Category
    {
        $query = Category::query()
            ->where('type', 'documentation');

        if (! empty($data['category_id'])) {
            $query->whereKey($data['category_id']);
        } elseif (! empty($data['category_slug'])) {
            $query->where('slug', $data['category_slug']);
        } else {
            throw ValidationException::withMessages([
                'category_id' => 'A documentation category is required.',
            ]);
        }

        $category = $query->first();

        if (! $category) {
            throw ValidationException::withMessages([
                'category_id' => 'The selected documentation category was not found.',
            ]);
        }

        return $category;
    }

    public function templateFromPayload(array $data, Category $category): DocumentationTemplate
    {
        $query = DocumentationTemplate::query()
            ->where('category_id', $category->id)
            ->where('is_active', true);

        if (! empty($data['template_id'])) {
            $query->whereKey($data['template_id']);
        }

        $template = $query->orderBy('id')->first();

        if (! $template) {
            throw ValidationException::withMessages([
                'template_id' => 'An active documentation template is required for the selected category.',
            ]);
        }

        return $template;
    }

    private function scopeFromPayload(array $data): array
    {
        $clientId = $data['client_id'] ?? null;
        $siteId = $data['site_id'] ?? null;

        if ($siteId) {
            $site = ClientSite::query()->find($siteId);

            if (! $site) {
                throw ValidationException::withMessages([
                    'site_id' => 'The selected site was not found.',
                ]);
            }

            if ($clientId && (int) $site->client_id !== (int) $clientId) {
                throw ValidationException::withMessages([
                    'site_id' => 'The selected site does not belong to the selected client.',
                ]);
            }

            return [
                'client_id' => $site->client_id,
                'work_context_id' => $this->workContexts->fromClientId($site->client_id)->id,
                'site_id' => $site->id,
                'scope_type' => 'site',
            ];
        }

        if ($clientId) {
            return [
                'client_id' => $clientId,
                'work_context_id' => $this->workContexts->fromClientId($clientId)->id,
                'site_id' => null,
                'scope_type' => 'client',
            ];
        }

        return [
            'client_id' => null,
            'work_context_id' => $this->workContexts->internal()->id,
            'site_id' => null,
            'scope_type' => 'internal',
        ];
    }

    private function documentData(array $data, array $existing = []): array
    {
        $documentData = array_replace($existing, $data['data'] ?? []);

        if (array_key_exists('content', $data)) {
            $documentData['content'] = $data['content'];
        } elseif (array_key_exists('body', $data)) {
            $documentData['content'] = $data['body'];
        }

        return $documentData;
    }

    private function snapshotForData(array $snapshot, array $data): array
    {
        if (! array_key_exists('content', $data) || $this->snapshotHasField($snapshot, 'content')) {
            return $snapshot;
        }

        $snapshot[] = ['layout' => 'rowStart', 'labelName' => 'Content'];
        $snapshot[] = ['Name' => 'content', 'labelName' => 'Content', 'type' => 'textarea'];

        return $snapshot;
    }

    private function snapshotHasField(array $snapshot, string $fieldName): bool
    {
        foreach ($snapshot as $field) {
            if (($field['Name'] ?? null) === $fieldName) {
                return true;
            }
        }

        return false;
    }
}
