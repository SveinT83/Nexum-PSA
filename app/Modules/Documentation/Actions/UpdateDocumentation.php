<?php

namespace App\Modules\Documentation\Actions;

use App\Modules\Documentation\Models\Documentation;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use Illuminate\Support\Facades\DB;

/** Browser and API edits share audience safety and portal update notifications. */
class UpdateDocumentation
{
    public function handle(Documentation $documentation, array $data): Documentation
    {
        return DB::transaction(function () use ($documentation, $data): Documentation {
            $document = Documentation::whereKey($documentation->id)->lockForUpdate()->firstOrFail();
            $audienceChanged = (int) ($data['client_id'] ?? $document->client_id) !== (int) $document->client_id
                || (array_key_exists('client_id', $data) && $data['client_id'] === null && $document->client_id)
                || (array_key_exists('site_id', $data) && (int) $data['site_id'] !== (int) $document->site_id)
                || (($data['scope_type'] ?? $document->scope_type) === 'internal');
            if ($audienceChanged) {
                $data['portal_visible_at'] = null;
                $data['portal_visible_by'] = null;
            }
            $document->fill($data)->save();
            if ($document->isPortalVisible() && $document->client_id) {
                app(SendCustomerPortalNotification::class)->handle(
                    type: 'portal_document_updated', clientId: (int) $document->client_id,
                    siteId: $document->site_id ? (int) $document->site_id : null,
                    title: 'Document updated', body: $document->title,
                    url: route('customer-portal.documents.show', $document),
                    sourceType: Documentation::class, sourceId: $document->id,
                    clientWideVisibleToSiteMembers: true, metadata: ['documentation_id' => $document->id],
                );
            }

            return $document->refresh();
        });
    }
}
