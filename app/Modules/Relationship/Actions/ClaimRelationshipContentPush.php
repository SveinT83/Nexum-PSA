<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncLink;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** One source-version/destination attempt across browser/API; ambiguous claims never resend. */
class ClaimRelationshipContentPush
{
    public function handle(NexumRelationship $relationship, Model $source, callable $send): NexumSyncLink
    {
        $destination = $relationship->only(['id', 'remote_base_url', 'remote_instance_id', 'client_id', 'vendor_id', 'direction',
            'capabilities', 'documentation_policy', 'attachment_policy', 'token_rotated_at']);
        $destination['credential_fingerprint'] = hash('sha256', (string) $relationship->getRawOriginal('outbound_token_encrypted')
            .'|'.(string) $relationship->getRawOriginal('webhook_secret_encrypted'));
        $key = hash('sha256', json_encode([$destination, $source->getMorphClass(), $source->getKey(), \App\Modules\Relationship\Support\RelationshipProjection::version($source)], JSON_THROW_ON_ERROR));
        $sourceHash = hash('sha256', $source->getMorphClass().':'.$source->getKey());
        $configurationVersion = \App\Modules\Relationship\Support\RelationshipProjection::version($relationship);
        $sourceVersion = \App\Modules\Relationship\Support\RelationshipProjection::version($source);
        $claimed = DB::transaction(function () use ($relationship, $source, $key, $configurationVersion, $sourceVersion, $sourceHash) {
            $freshRelationship = NexumRelationship::query()->whereKey($relationship->id)->lockForUpdate()->firstOrFail();
            $freshSource = $source->newQuery()->whereKey($source->getKey())->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($configurationVersion, \App\Modules\Relationship\Support\RelationshipProjection::version($freshRelationship))
                && hash_equals($sourceVersion, \App\Modules\Relationship\Support\RelationshipProjection::version($freshSource)), 412, 'Source or destination changed before the outbound claim.');
            $existing = DB::table('relationship_outbound_claims')->where('id', $key)->first();
            if ($existing) {
                return $existing;
            }
            abort_if(DB::table('relationship_outbound_claims')->where('relationship_id', $relationship->id)->where('source_hash', $sourceHash)
                ->whereIn('state', ['reserved', 'unresolved'])->exists(), 409, 'An earlier source attempt needs reconciliation before a new export.');
            DB::table('relationship_outbound_claims')->insert(['id' => $key, 'relationship_id' => $relationship->id,
                'source_hash' => $sourceHash, 'state' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);

            return null;
        });
        if ($claimed) {
            abort_unless($claimed->sync_link_id && $claimed->state === 'completed', 409, 'An earlier content push requires reconciliation; do not resend.');

            abort_unless($claimed->result, 409, 'The original immutable result requires review.');

            return (new NexumSyncLink)->newFromBuilder(json_decode(\Illuminate\Support\Facades\Crypt::decryptString($claimed->result), true, 512, JSON_THROW_ON_ERROR));
        }
        try {
            $link = $send();
            DB::table('relationship_outbound_claims')->where('id', $key)->update([
                'state' => $link->sync_status === 'unresolved' ? 'unresolved' : 'completed',
                'sync_link_id' => $link->id, 'result' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode($link->getAttributes(), JSON_THROW_ON_ERROR)), 'updated_at' => now()]);

            return $link;
        } catch (\Throwable $error) {
            DB::table('relationship_outbound_claims')->where('id', $key)->update(['state' => 'unresolved', 'updated_at' => now()]);
            throw $error;
        }
    }
}
