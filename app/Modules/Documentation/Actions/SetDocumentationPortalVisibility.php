<?php

namespace App\Modules\Documentation\Actions;

use App\Modules\CustomerPortal\Actions\RecordCustomerPortalAudit;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared explicit publication side effects for today's mutable-document workflow. */
class SetDocumentationPortalVisibility
{
    public function handle(Documentation $documentation, bool $visible, Request $request): Documentation
    {
        return DB::transaction(function () use ($documentation, $visible, $request): Documentation {
            $document = Documentation::whereKey($documentation->id)->lockForUpdate()->firstOrFail();
            if ($visible && (! $document->client_id || $document->scope_type === 'internal')) {
                throw ValidationException::withMessages(['portal_visible' => 'Only client-scoped documentation can be shown in the customer portal.']);
            }
            $wasVisible = $document->isPortalVisible();
            $document->forceFill([
                'portal_visible_at' => $visible ? ($document->portal_visible_at ?: now()) : null,
                'portal_visible_by' => $visible ? $request->user()->id : null,
            ])->save();
            app(RecordCustomerPortalAudit::class)->handle(
                $visible ? 'portal_documentation_visibility_enabled' : 'portal_documentation_visibility_disabled',
                null, $request->user(), null, $document->client, $document->site,
                ['documentation_id' => $document->id, 'title' => $document->title, 'scope_type' => $document->scope_type],
                $request,
            );
            if (! $wasVisible && $visible) {
                app(SendCustomerPortalNotification::class)->handle(
                    type: 'portal_document_published', clientId: (int) $document->client_id,
                    siteId: $document->site_id ? (int) $document->site_id : null,
                    title: 'New document published', body: $document->title,
                    url: route('customer-portal.documents.show', $document),
                    sourceType: Documentation::class, sourceId: $document->id,
                    clientWideVisibleToSiteMembers: true,
                    metadata: ['documentation_id' => $document->id, 'scope_type' => $document->scope_type],
                );
            }

            return $document->refresh();
        });
    }
}
