<?php

namespace App\Modules\Notification\Support;

/**
 * Hash the exact canonical notification identity and JSON bytes consumed by an
 * external delivery. Length-prefixing keeps adjacent fields unambiguous while
 * preserving the stored JSON byte representation instead of re-encoding it.
 */
final class CanonicalNotificationPayloadAttestation
{
    public static function hash(
        string $notificationId,
        string $notificationType,
        string $deliveryIdentity,
        string $notifiableType,
        int|string $notifiableId,
        #[\SensitiveParameter] string $canonicalJson,
    ): string {
        $encoded = '';
        foreach ([
            $notificationId,
            $notificationType,
            $deliveryIdentity,
            $notifiableType,
            (string) $notifiableId,
            $canonicalJson,
        ] as $value) {
            $encoded .= strlen($value).':'.$value;
        }

        return hash('sha256', $encoded);
    }
}
