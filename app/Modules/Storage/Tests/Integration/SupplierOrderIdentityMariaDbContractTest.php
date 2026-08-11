<?php

namespace App\Modules\Storage\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Opt-in MariaDB contract for the generated supplier-order identity boundary.
 *
 * CI or an operator may provide a dedicated test DSN through the three
 * NEXUM_MARIADB_IDENTITY_* variables. The test creates one uniquely named
 * table, uses two independent connections, and always removes the table.
 */
class SupplierOrderIdentityMariaDbContractTest extends TestCase
{
    private ?PDO $first = null;

    private ?PDO $second = null;

    private ?string $table = null;

    protected function setUp(): void
    {
        parent::setUp();

        $dsn = getenv('NEXUM_MARIADB_IDENTITY_DSN') ?: null;
        $user = getenv('NEXUM_MARIADB_IDENTITY_USER') ?: null;
        $password = getenv('NEXUM_MARIADB_IDENTITY_PASSWORD');
        if ($dsn === null || $user === null || $password === false) {
            $this->markTestSkipped('Dedicated MariaDB identity contract credentials are not configured.');
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $this->first = new PDO($dsn, $user, $password, $options);
        $this->second = new PDO($dsn, $user, $password, $options);
        if ($this->first->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            $this->markTestSkipped('The configured identity contract connection is not MariaDB/MySQL.');
        }

        $this->table = 'nexum_po_identity_contract_'.bin2hex(random_bytes(6));
        $this->first->exec(
            "CREATE TABLE `{$this->table}` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                vendor_id BIGINT UNSIGNED NOT NULL,
                vendor_ref VARCHAR(255) NULL,
                supplier_order_identity_key VARCHAR(255)
                    COLLATE utf8mb4_bin
                    AS (NULLIF(UPPER(TRIM(vendor_ref)), '')) VIRTUAL,
                UNIQUE KEY supplier_identity_unique
                    (vendor_id, supplier_order_identity_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    protected function tearDown(): void
    {
        if ($this->first !== null && $this->table !== null) {
            $this->first->exec("DROP TABLE IF EXISTS `{$this->table}`");
        }

        $this->second = null;
        $this->first = null;
        parent::tearDown();
    }

    #[Test]
    public function generated_identity_and_locking_read_hold_under_two_real_connections(): void
    {
        $insert = $this->first->prepare(
            "INSERT INTO `{$this->table}` (vendor_id, vendor_ref) VALUES (?, ?)"
        );
        $insert->execute([10, '  0012-order  ']);

        $this->expectDuplicate(fn () => $insert->execute([10, '0012-ORDER']));
        $insert->execute([11, '0012-ORDER']);
        $insert->execute([10, null]);
        $insert->execute([10, '   ']);
        $insert->execute([10, '  A B-001  ']);

        $this->assertSame(
            'A B-001',
            $this->first->query(
                "SELECT supplier_order_identity_key FROM `{$this->table}`
                 WHERE vendor_ref = '  A B-001  '"
            )->fetchColumn(),
        );

        $updateId = (int) $this->first->lastInsertId();
        $this->expectDuplicate(function () use ($updateId): void {
            $statement = $this->first->prepare(
                "UPDATE `{$this->table}` SET vendor_ref = ? WHERE id = ?"
            );
            $statement->execute(['0012-order', $updateId]);
        });

        $this->first->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->first->beginTransaction();
        $absent = $this->first->prepare(
            "SELECT id FROM `{$this->table}`
             WHERE vendor_id = ? AND supplier_order_identity_key = NULLIF(UPPER(TRIM(?)), '')"
        );
        $absent->execute([20, 'RACE-42']);
        $this->assertFalse($absent->fetchColumn());

        $writer = $this->second->prepare(
            "INSERT INTO `{$this->table}` (vendor_id, vendor_ref) VALUES (?, ?)"
        );
        $writer->execute([20, 'RACE-42']);

        $absent->execute([20, 'RACE-42']);
        $this->assertFalse($absent->fetchColumn(), 'A plain read should retain the old RR snapshot.');

        $locking = $this->first->prepare(
            "SELECT id FROM `{$this->table}`
             WHERE vendor_id = ? AND supplier_order_identity_key = NULLIF(UPPER(TRIM(?)), '')
             FOR UPDATE"
        );
        $locking->execute([20, 'RACE-42']);
        $this->assertIsNumeric($locking->fetchColumn());
        $this->first->rollBack();
    }

    private function expectDuplicate(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected the generated supplier identity unique key to reject the write.');
        } catch (\PDOException $exception) {
            $this->assertSame('23000', $exception->getCode());
        }
    }
}
