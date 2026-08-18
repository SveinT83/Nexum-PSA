<?php

namespace App\Modules\Email\Services;

use ErrorException;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Exceptions\ProtocolNotSupportedException;
use Webklex\PHPIMAP\Exceptions\RuntimeException;

/**
 * Reconciliation-only Webklex client using the pre-allocation bounded IMAP
 * protocol. Ordinary provider mutation clients retain the vendor connection.
 */
final class EmailProviderReconciliationImapClient extends Client
{
    public function __construct(#[\SensitiveParameter] Config $config)
    {
        parent::__construct($config);
    }

    public function connect(): Client
    {
        $this->disconnect();
        $protocol = strtolower($this->protocol);
        if (! in_array($protocol, ['imap', 'imap4', 'imap4rev1'], true)) {
            throw new ConnectionFailedException(
                'connection setup failed',
                0,
                new ProtocolNotSupportedException('unsupported reconciliation protocol'),
            );
        }

        $this->connection = new EmailProviderReconciliationImapProtocol(
            $this->config,
            $this->validate_cert,
            $this->encryption,
        );
        $this->connection->setConnectionTimeout($this->timeout);
        $this->connection->setProxy($this->proxy);
        $this->connection->setSslOptions($this->ssl_options);

        if ($this->config->get('options.debug')) {
            $this->connection->enableDebug();
        }
        if (! $this->config->get('options.uid_cache')) {
            $this->connection->disableUidCache();
        }

        try {
            $this->connection->connect($this->host, $this->port);
        } catch (ErrorException|RuntimeException $exception) {
            throw new ConnectionFailedException('connection setup failed', 0, $exception);
        }

        $this->authenticate();

        return $this;
    }
}
