<?php

namespace App\Modules\Storage\Tests\Unit;

use App\Modules\Storage\Support\SupplierOrderIdentity;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplierOrderIdentityTest extends TestCase
{
    #[Test]
    public function it_normalizes_only_case_and_surrounding_spaces(): void
    {
        $this->assertSame('0012-A B', SupplierOrderIdentity::normalize('  0012-a b  '));
        $this->assertNull(SupplierOrderIdentity::normalize('   '));
        $this->assertNull(SupplierOrderIdentity::storedReference(null));
        $this->assertSame('0012-a b', SupplierOrderIdentity::storedReference('  0012-a b  '));
        $this->assertSame(
            "\t0012-A B\t",
            SupplierOrderIdentity::normalize("\t0012-a b\t"),
        );
    }

    #[Test]
    public function case_and_trim_variants_have_the_same_supplier_scoped_hash(): void
    {
        $this->assertSame(
            SupplierOrderIdentity::hash(10, '  order-42  '),
            SupplierOrderIdentity::hash(10, 'ORDER-42'),
        );
        $this->assertNotSame(
            SupplierOrderIdentity::hash(10, 'ORDER-42'),
            SupplierOrderIdentity::hash(11, 'ORDER-42'),
        );
    }

    #[Test]
    public function significant_formatting_is_not_fuzzily_merged(): void
    {
        $this->assertNotSame(
            SupplierOrderIdentity::hash(10, '0012-A B'),
            SupplierOrderIdentity::hash(10, '12-AB'),
        );
        $this->assertNotSame(
            SupplierOrderIdentity::hash(10, '0012-A B'),
            SupplierOrderIdentity::hash(10, '0012-AB'),
        );
        $this->assertNotSame(
            SupplierOrderIdentity::hash(10, 'stra?e'),
            SupplierOrderIdentity::hash(10, 'strasse'),
        );
    }
}
