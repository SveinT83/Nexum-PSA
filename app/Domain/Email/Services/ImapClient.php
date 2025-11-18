<?php

namespace App\Domain\Email\Services;

use App\Domain\Email\Models\EmailAccount;
use Illuminate\Support\Facades\Crypt;
use Webklex\PHPIMAP\Client;
use Webklex\IMAP\Facades\Client as ImapClientFacade;

class ImapClient
{
    protected Client $client;

    public function __construct(protected EmailAccount $account) {}

    public function connect(): void
    {
        // Map encryption: starttls -> tls (Webklex expects 'tls')
        $rawEnc = strtolower((string)$this->account->imap_encryption);
        $encryption = match ($rawEnc) {
            'starttls' => 'tls',
            'tls' => 'tls',
            'ssl' => 'ssl',
            default => null,
        };

        $this->client = ImapClientFacade::make([
            'host'          => $this->account->imap_host,
            'port'          => (int)$this->account->imap_port,
            'encryption'    => $encryption, // 'ssl'|'tls'|null
            'validate_cert' => true,
            'username'      => $this->account->imap_username,
            'password'      => Crypt::decryptString($this->account->imap_secret),
            'protocol'      => 'imap',
            'timeout'       => 20,
        ]);
        $this->client->connect();
    }

    /**
     * Fetch up to $limit unseen messages from INBOX and return lightweight payloads.
     * NOTE: Does not delete/move messages; caller decides after persistence.
     */
    public function fetchUnseen(int $limit = 20): array
    {
        // Folder retrieval differences across versions: try getFolderByPath first
        $folder = method_exists($this->client, 'getFolderByPath')
            ? $this->client->getFolderByPath('INBOX')
            : $this->client->getFolder('INBOX');

        $messages = $folder->messages()->unseen()->limit($limit)->get();
        $result = [];
        foreach ($messages as $msg) {
            $fromList = $this->normalizeAddressList($msg->getFrom());
            $from = $fromList[0] ?? null;

            $toList = $this->normalizeAddressList($msg->getTo());
            $ccList = $this->normalizeAddressList($msg->getCc());
            $references = $this->normalizeScalarList($msg->getReferences());

            $result[] = [
                'imap_uid'    => (int)$msg->getUid(),
                'message_id'  => $this->normalizeString($msg->getMessageId()),
                'subject'     => $this->normalizeString($msg->getSubject()),
                'from_name'   => $from['name'] ?? null,
                'from_email'  => $from['email'] ?? null,
                'to'          => $toList,
                'cc'          => $ccList,
                'in_reply_to' => $this->normalizeString($msg->getInReplyTo()),
                'references'  => implode(' ', $references),
                'headers'     => $msg->getHeaders()->toArray(),
                'received_at' => $this->normalizeDate($msg->getDate()),
                'size_bytes'  => $msg->getSize() ?? null,
            ];
        }
        return $result;
    }

    /**
     * Fetch a single message by IMAP UID from INBOX.
     */
    public function fetchByUid(int $uid)
    {
        $folder = method_exists($this->client, 'getFolderByPath')
            ? $this->client->getFolderByPath('INBOX')
            : $this->client->getFolder('INBOX');

        // Preferred (v6) API
        if (method_exists($folder, 'query')) {
            $message = $folder->query()->getMessageByUid($uid);
            if ($message !== null) {
                return $message;
            }
        }

        // Fallback: iterate messages
        foreach ($folder->messages()->get() as $m) {
            if ((int)$m->getUid() === $uid) {
                return $m;
            }
        }
        return null;
    }

    public function disconnect(): void
    {
        if (isset($this->client)) {
            try { $this->client->disconnect(); } catch (\Throwable $e) { /* swallow */ }
        }
    }

    /**
     * Normalize an address list (various Webklex attribute types) into
     * an array of ['name' => string|null, 'email' => string|null].
     */
    private function normalizeAddressList($attr): array
    {
        if ($attr === null) return [];

        // Convert attribute/collection to array when possible
        if (is_object($attr)) {
            if (method_exists($attr, 'toArray')) {
                $attr = $attr->toArray();
            } elseif ($attr instanceof \Traversable) {
                $attr = iterator_to_array($attr);
            }
        }

        if (!is_array($attr)) {
            return [];
        }

        $out = [];
        foreach ($attr as $a) {
            $name = null; $email = null;
            if (is_array($a)) {
                $name  = $a['personal'] ?? $a['name'] ?? null;
                $email = $a['mail'] ?? $a['email'] ?? ($a['address'] ?? null);
                if (!$email && isset($a['mailbox'], $a['host'])) {
                    $email = $a['mailbox'].'@'.$a['host'];
                }
            } elseif (is_object($a)) {
                // Try common property names and methods
                $name  = $a->personal ?? $a->name ?? (method_exists($a, 'getName') ? $a->getName() : null);
                $email = $a->mail ?? $a->email ?? (method_exists($a, 'getAddress') ? $a->getAddress() : null);
                if (!$email) {
                    $mailbox = $a->mailbox ?? (method_exists($a, 'getMailbox') ? $a->getMailbox() : null);
                    $host    = $a->host ?? (method_exists($a, 'getHost') ? $a->getHost() : null);
                    if ($mailbox && $host) $email = $mailbox.'@'.$host;
                }
            }
            $out[] = ['name' => $name, 'email' => $email];
        }
        return $out;
    }

    /**
     * Normalize scalar list attributes (e.g., References) to a simple array of strings.
     */
    private function normalizeScalarList($attr): array
    {
        if ($attr === null) return [];
        if (is_string($attr)) return [$attr];
        if (is_object($attr)) {
            if (method_exists($attr, 'toArray')) {
                $attr = $attr->toArray();
            } elseif ($attr instanceof \Traversable) {
                $attr = iterator_to_array($attr);
            }
        }
        return is_array($attr) ? $attr : [];
    }

    /**
     * Normalize a date attribute to 'Y-m-d H:i:s' string.
     */
    private function normalizeDate($attr): string
    {
        try {
            if ($attr instanceof \DateTimeInterface) {
                return $attr->format('Y-m-d H:i:s');
            }
            if (is_object($attr)) {
                if (method_exists($attr, 'toDateTime')) {
                    $dt = $attr->toDateTime();
                    if ($dt instanceof \DateTimeInterface) {
                        return $dt->format('Y-m-d H:i:s');
                    }
                }
                if (method_exists($attr, 'toCarbon')) {
                    $c = $attr->toCarbon();
                    if ($c instanceof \DateTimeInterface) {
                        return $c->format('Y-m-d H:i:s');
                    }
                }
                if (method_exists($attr, '__toString')) {
                    $s = (string)$attr;
                    $dt = new \DateTimeImmutable($s);
                    return $dt->format('Y-m-d H:i:s');
                }
            }
            if (is_string($attr)) {
                $dt = new \DateTimeImmutable($attr);
                return $dt->format('Y-m-d H:i:s');
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return now()->toDateTimeString();
    }

    /**
     * Normalize a scalar/attribute to string.
     */
    private function normalizeString($attr): ?string
    {
        if ($attr === null) return null;
        if (is_string($attr)) return $attr === '' ? null : $attr;
        if (is_object($attr)) {
            if (method_exists($attr, 'toString')) {
                $s = $attr->toString();
                return $s === '' ? null : (string)$s;
            }
            if (method_exists($attr, '__toString')) {
                $s = (string)$attr;
                return $s === '' ? null : $s;
            }
            if (method_exists($attr, 'getValue')) {
                $v = $attr->getValue();
                return $v === '' ? null : (string)$v;
            }
        }
        if (is_scalar($attr)) {
            $s = (string)$attr;
            return $s === '' ? null : $s;
        }
        return null;
    }
}
