<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Storage\Actions\ActivateSupplierOrderProfileVersion;
use App\Modules\Storage\Actions\CloneSupplierOrderProfileVersion;
use App\Modules\Storage\Actions\CreateSupplierOrderProfileVersion;
use App\Modules\Storage\Actions\ExportSupplierOrderProfile;
use App\Modules\Storage\Actions\ImportSupplierOrderProfile;
use App\Modules\Storage\Actions\PauseSupplierOrderProfile;
use App\Modules\Storage\Actions\RetireSupplierOrderProfile;
use App\Modules\Storage\Actions\RollbackSupplierOrderProfileVersion;
use App\Modules\Storage\Actions\ValidateSupplierOrderProfileVersion;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileFixture;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderDeterministicExtractor;
use App\Modules\Storage\Support\SupplierOrderDocumentNormalizer;
use App\Modules\Storage\Support\SupplierOrderLocaleParser;
use App\Modules\Storage\Support\SupplierOrderProfileDefinitionValidator;
use App\Modules\Storage\Support\SupplierOrderProfileFactoryData;
use App\Modules\Storage\Support\SupplierOrderProfileMatcher;
use App\Modules\Storage\Support\SupplierOrderProfileMatchResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SupplierOrderProfileEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    #[Test]
    public function itegra_factory_is_strict_and_executable_or_unsafe_profiles_are_rejected(): void
    {
        $validator = app(SupplierOrderProfileDefinitionValidator::class);
        $definition = SupplierOrderProfileFactoryData::itegra();
        $this->assertTrue($validator->validate($definition)->valid());

        $unscoped = $definition;
        $unscoped['match']['account_ids'] = [];
        $unscoped['match']['mailboxes'] = [];
        $unscoped['match']['recipients'] = [];
        $unscopedResult = $validator->validate($unscoped);
        $this->assertFalse($unscopedResult->valid());
        $this->assertContains('match_ingress_scope_missing', collect($unscopedResult->errors)->pluck('code')->all());

        $localeParser = app(SupplierOrderLocaleParser::class);
        $this->assertSame('-12.5', $localeParser->decimal('-12,50', $definition['locale']));
        $this->assertNull($localeParser->date('August 5, 2026', $definition['locale']));

        $remote = SupplierOrderProfileFactoryData::itegra();
        $remote['callback'] = 'https://attacker.invalid/profile';
        $result = $validator->validate($remote);
        $this->assertFalse($result->valid());
        $this->assertContains('definition_executable_key_forbidden', collect($result->errors)->pluck('code')->all());

        $unsafeRegex = SupplierOrderProfileFactoryData::itegra();
        $unsafeRegex['lines']['repeated_regex']['pattern'] = '(?<description>(a+)+)(?<supplier_sku>[A-Z]{1,10})(?<quantity>[0-9]{1,3})(?<line_total>[0-9]{1,3})';
        $result = $validator->validate($unsafeRegex);
        $this->assertFalse($result->valid());
        $this->assertContains('pattern_nested_quantifier_forbidden', collect($result->errors)->pluck('code')->all());

        $unsafeDefaults = SupplierOrderProfileFactoryData::itegra();
        $unsafeDefaults['item_defaults']['moq'] = 0;
        $result = $validator->validate($unsafeDefaults);
        $this->assertFalse($result->valid());
        $this->assertContains('profile_item_default_integer_invalid', collect($result->errors)->pluck('code')->all());

        $unknownDefaults = SupplierOrderProfileFactoryData::itegra();
        $unknownDefaults['defaults']['time_zone'] = 'Europe/Oslo';
        $result = $validator->validate($unknownDefaults);
        $this->assertFalse($result->valid());
        $this->assertContains('definition_key_unknown', collect($result->errors)->pluck('code')->all());
    }

    #[Test]
    public function normalizer_produces_bounded_evidence_and_removes_scripts_urls_and_attributes(): void
    {
        $normalized = app(SupplierOrderDocumentNormalizer::class)->normalize([
            ...$this->sourceSnapshot(),
            'body_text' => '',
            'body_html' => '<script>alert(1)</script><p data-secret="x">See https://example.invalid/a</p>'
                .'<table><tr><th>Vare</th><th>Antall</th></tr>'
                .'<tr><td>Router</td><td>2</td></tr></table>',
        ]);

        $this->assertStringNotContainsString('alert(1)', $normalized->searchText);
        $this->assertStringNotContainsString('example.invalid', $normalized->searchText);
        $this->assertStringContainsString('[URL]', $normalized->searchText);
        $this->assertSame('Router', $normalized->tables[0]['rows'][0]['cells']['Vare']);
        $this->assertSame('t0001.r0001', $normalized->tables[0]['rows'][0]['id']);
        $this->assertSame('html_table', $normalized->anchorForQuote('Router')['source']);
    }

    #[Test]
    public function deterministic_itegra_plain_text_extraction_maps_lines_totals_and_evidence_without_side_effects(): void
    {
        $before = $this->operationalCounts();
        $result = app(SupplierOrderDeterministicExtractor::class)->extractDefinition(
            SupplierOrderProfileFactoryData::itegra(),
            $this->sourceSnapshot(),
        );

        $this->assertTrue($result->valid(), json_encode($result->errors));
        $this->assertSame('9900000001', data_get($result->document, 'external_order_number'));
        $this->assertSame('Itegra', data_get($result->document, 'supplier.name'));
        $this->assertSame('2026-08-05', data_get($result->document, 'ordered_at'));
        $this->assertSame('received_at_fallback', data_get($result->document, 'ordered_at_provenance'));
        $this->assertSame('NX-SYN-1001', data_get($result->document, 'lines.0.supplier_sku'));
        $this->assertSame(1, data_get($result->document, 'lines.0.quantity'));
        $this->assertSame('100', data_get($result->document, 'lines.0.line_total'));
        $this->assertSame('125', data_get($result->document, 'totals.total_ex_tax'));
        $this->assertNotEmpty(data_get($result->document, 'lines.0.evidence.quantity.block_id'));
        $this->assertNotEmpty(data_get($result->document, 'evidence.external_order_number.block_id'));
        $this->assertSame($before, $this->operationalCounts());
        Http::assertNothingSent();
    }

    #[Test]
    public function deterministic_extraction_reads_html_product_tables_with_stable_row_anchors(): void
    {
        $snapshot = $this->sourceSnapshot();
        $snapshot['body_text'] = '';
        $snapshot['body_html'] = <<<'HTML'
<p>Takk for din ordre.</p>
<p>Ordresammendrag:</p>
<p>Ordrenr.: 9900000001 (Se ordrestatus)</p>
<p>Total varer</p><p>Frakt</p><p>Verdikode</p><p>Totalt eks. MVA:</p>
<p>100,00</p><p>25,00</p><p>0,00</p><p>125,00</p>
<table>
<tr><th>Vare</th><th>Antall</th><th>Total</th></tr>
<tr><td>Nexum synthetic profile fixture<br>Varenr: (NX-SYN-1001)</td><td>1</td><td>100,00</td></tr>
</table>
HTML;

        $result = app(SupplierOrderDeterministicExtractor::class)->extractDefinition(
            SupplierOrderProfileFactoryData::itegra(),
            $snapshot,
        );

        $this->assertTrue($result->valid(), json_encode($result->errors));
        $this->assertSame('NX-SYN-1001', data_get($result->document, 'lines.0.supplier_sku'));
        $this->assertSame('t0001.r0001', data_get($result->document, 'lines.0.evidence.supplier_sku.row_id'));
        $this->assertSame('html_table', data_get($result->document, 'lines.0.evidence.quantity.source'));
    }

    #[Test]
    public function matcher_requires_trusted_alignment_and_returns_only_a_unique_lowest_priority_profile(): void
    {
        $definition = SupplierOrderProfileFactoryData::itegra();
        $first = $this->activeProfile('itegra-primary', 20, $definition);
        $second = $this->activeProfile('itegra-secondary', 30, $definition);

        $matched = app(SupplierOrderProfileMatcher::class)->match($this->sourceSnapshot());
        $this->assertSame(SupplierOrderProfileMatchResult::STATUS_MATCHED, $matched->status);
        $this->assertSame($first->id, $matched->profile?->id);
        $this->assertSame($first->active_version_id, $matched->version?->id);

        $untrusted = $this->sourceSnapshot();
        $untrusted['trusted_auth']['aligned'] = false;
        $this->assertSame(
            SupplierOrderProfileMatchResult::STATUS_NONE,
            app(SupplierOrderProfileMatcher::class)->match($untrusted)->status,
        );

        $second->update(['priority' => 20]);
        $ambiguous = app(SupplierOrderProfileMatcher::class)->match($this->sourceSnapshot());
        $this->assertSame(SupplierOrderProfileMatchResult::STATUS_AMBIGUOUS, $ambiguous->status);
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $ambiguous->candidateProfileIds);
    }

    #[Test]
    public function matcher_requires_the_configured_email_account_and_mailbox_without_a_recipient_scope(): void
    {
        $definition = SupplierOrderProfileFactoryData::itegra();
        $definition['match']['account_ids'] = [42];
        $definition['match']['mailboxes'] = ['orders@nexum.test'];
        $definition['match']['recipients'] = [];
        $profile = $this->activeProfile('account-scoped', 10, $definition);
        $source = $this->sourceSnapshot();
        $source['account_id'] = 41;

        $this->assertSame(
            SupplierOrderProfileMatchResult::STATUS_NONE,
            app(SupplierOrderProfileMatcher::class)->match($source)->status,
        );

        $source['account_id'] = 42;
        $matched = app(SupplierOrderProfileMatcher::class)->match($source);
        $this->assertSame(SupplierOrderProfileMatchResult::STATUS_MATCHED, $matched->status);
        $this->assertSame($profile->id, $matched->profile?->id);

        $source['mailbox'] = 'other-mailbox';
        $this->assertSame(
            SupplierOrderProfileMatchResult::STATUS_NONE,
            app(SupplierOrderProfileMatcher::class)->match($source)->status,
        );
    }

    #[Test]
    public function version_creation_is_idempotent_and_clone_preserves_immutable_parentage(): void
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $profile = $this->draftProfile('versioning');
        $create = app(CreateSupplierOrderProfileVersion::class);
        $definition = SupplierOrderProfileFactoryData::itegra();

        $first = $create->handle($profile, $definition, actor: $actor);
        $same = $create->handle($profile, $definition, actor: $actor);
        $this->assertSame($first->id, $same->id);

        $candidate = $definition;
        $candidate['validation']['max_order_total'] = 50000000;
        $clone = app(CloneSupplierOrderProfileVersion::class)->handle($first, $candidate, $actor);
        $this->assertSame(2, $clone->version_number);
        $this->assertSame($first->id, $clone->parent_version_id);
        $this->assertSame(StableJson::checksum($candidate), $clone->checksum);
        $this->assertSame('clone', $clone->source);

        $clone->definition = $definition;
        $this->expectException(\LogicException::class);
        $clone->save();
    }

    #[Test]
    public function activation_replays_protected_fixtures_under_lock_and_rejects_stale_validation(): void
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $profile = $this->draftProfile('fixture-gate');
        $version = app(CreateSupplierOrderProfileVersion::class)->handle(
            $profile,
            SupplierOrderProfileFactoryData::itegra(),
            actor: $actor,
        );
        $fixture = $this->protectedFixture($profile, $version);

        $validation = app(ValidateSupplierOrderProfileVersion::class)->handle($version);
        $this->assertTrue($validation->valid(), json_encode($validation->errors));
        $this->assertSame(PurchaseOrderImportProfileVersion::STATUS_VALIDATED, $version->fresh()->status);

        $staleExpected = (array) $fixture->expected_document;
        data_set($staleExpected, 'external_order_number', 'WRONG');
        $fixture->update([
            'expected_document' => $staleExpected,
            'expected_checksum' => StableJson::checksum($staleExpected),
        ]);

        try {
            app(ActivateSupplierOrderProfileVersion::class)->handle($version->fresh(), $actor, 'Enable tested Itegra profile');
            $this->fail('Activation should replay and reject the changed protected fixture.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fixtures', $exception->errors());
        }

        $expected = $this->expectedSubset();
        $fixture->update([
            'expected_document' => $expected,
            'expected_checksum' => StableJson::checksum($expected),
        ]);
        $activated = app(ActivateSupplierOrderProfileVersion::class)->handle(
            $version->fresh(),
            $actor,
            'Enable tested Itegra profile',
        );

        $this->assertSame(PurchaseOrderImportProfile::STATE_ACTIVE, $activated->lifecycle_state);
        $this->assertSame($version->id, $activated->active_version_id);
        $this->assertSame(PurchaseOrderImportProfileVersion::STATUS_ACTIVE, $version->fresh()->status);
        $this->assertSame($this->operationalCounts(), [
            'vendors' => 0,
            'items' => 0,
            'purchase_orders' => 0,
            'purchase_receipts' => 0,
            'movements' => 0,
            'stock_units' => 0,
        ]);
    }

    #[Test]
    public function activated_immutable_version_match_scope_is_authoritative_at_runtime(): void
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $profile = $this->draftProfile('version-match-authority');
        $first = app(CreateSupplierOrderProfileVersion::class)->handle(
            $profile,
            SupplierOrderProfileFactoryData::itegra(),
            actor: $actor,
        );
        $this->protectedFixture($profile, $first);
        app(ValidateSupplierOrderProfileVersion::class)->handle($first);
        app(ActivateSupplierOrderProfileVersion::class)->handle($first->fresh(), $actor, 'Initial source scope');

        $candidate = SupplierOrderProfileFactoryData::itegra();
        $candidate['match']['recipients'] = ['new-purchasing@example.invalid'];
        $candidate['match']['senders'] = ['confirmations@itegra.no'];
        $second = app(CloneSupplierOrderProfileVersion::class)->handle($first->fresh(), $candidate, $actor);
        app(ValidateSupplierOrderProfileVersion::class)->handle($second);
        $activated = app(ActivateSupplierOrderProfileVersion::class)->handle(
            $second->fresh(),
            $actor,
            'Move to the reviewed supplier sender and recipient.',
        );

        $this->assertSame($candidate['match'], $activated->matching_scope);
        $this->assertSame(
            SupplierOrderProfileMatchResult::STATUS_NONE,
            app(SupplierOrderProfileMatcher::class)->match($this->sourceSnapshot())->status,
        );

        $newSource = $this->sourceSnapshot();
        $newSource['from']['email'] = 'confirmations@itegra.no';
        $newSource['to'] = [[
            'name' => 'New purchasing',
            'email' => 'new-purchasing@example.invalid',
        ]];
        $matched = app(SupplierOrderProfileMatcher::class)->match($newSource);
        $this->assertSame(SupplierOrderProfileMatchResult::STATUS_MATCHED, $matched->status);
        $this->assertSame($second->id, $matched->version?->id);
    }

    #[Test]
    public function lifecycle_rollback_and_portable_import_remain_fixture_gated_and_draft_by_default(): void
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $profile = $this->draftProfile('lifecycle');
        $first = app(CreateSupplierOrderProfileVersion::class)->handle(
            $profile,
            SupplierOrderProfileFactoryData::itegra(),
            actor: $actor,
        );
        $this->protectedFixture($profile, $first);
        app(ValidateSupplierOrderProfileVersion::class)->handle($first);
        app(ActivateSupplierOrderProfileVersion::class)->handle($first->fresh(), $actor, 'Initial activation');

        $candidate = SupplierOrderProfileFactoryData::itegra();
        $candidate['validation']['max_order_total'] = 50000000;
        $second = app(CloneSupplierOrderProfileVersion::class)->handle($first, $candidate, $actor);
        app(ValidateSupplierOrderProfileVersion::class)->handle($second);
        app(ActivateSupplierOrderProfileVersion::class)->handle($second->fresh(), $actor, 'Activate safer limits');

        $rolledBack = app(RollbackSupplierOrderProfileVersion::class)->handle(
            $profile->fresh(),
            $first->fresh(),
            $actor,
            'Vendor template regression',
        );
        $this->assertSame($first->id, $rolledBack->active_version_id);
        $this->assertSame(PurchaseOrderImportProfileVersion::STATUS_SUPERSEDED, $second->fresh()->status);

        $paused = app(PauseSupplierOrderProfile::class)->handle($rolledBack, 'Investigating changed email', $actor);
        $this->assertSame(PurchaseOrderImportProfile::STATE_PAUSED, $paused->lifecycle_state);

        $export = app(ExportSupplierOrderProfile::class)->handle($paused, $first->fresh());
        $this->assertSame([], data_get($export, 'version.definition.match.account_ids'));
        $this->assertSame([], data_get($export, 'version.definition.match.mailboxes'));
        $this->assertSame([], data_get($export, 'version.definition.match.senders'));
        $this->assertSame(['configure-local-routing@example.invalid'], data_get($export, 'version.definition.match.recipients'));
        $this->assertNull(data_get($export, 'version.definition.defaults.warehouse_id'));
        $import = app(ImportSupplierOrderProfile::class)->handle($export, $actor, 'lifecycle-copy');
        $this->assertSame(PurchaseOrderImportProfile::STATE_DRAFT, $import['profile']->lifecycle_state);
        $this->assertNull($import['profile']->active_version_id);
        $this->assertSame(PurchaseOrderImportProfileVersion::STATUS_DRAFT, $import['version']->status);
        $this->assertSame(data_get($export, 'version.checksum'), $import['version']->checksum);
        $this->assertNotSame($first->checksum, $import['version']->checksum);

        $retired = app(RetireSupplierOrderProfile::class)->handle($paused, 'Replaced by maintained copy', $actor);
        $this->assertSame(PurchaseOrderImportProfile::STATE_RETIRED, $retired->lifecycle_state);
        Http::assertNothingSent();
    }

    private function draftProfile(string $slug, int $priority = 100): PurchaseOrderImportProfile
    {
        return PurchaseOrderImportProfile::query()->create([
            'name' => ucfirst(str_replace('-', ' ', $slug)),
            'slug' => $slug,
            'lifecycle_state' => PurchaseOrderImportProfile::STATE_DRAFT,
            'priority' => $priority,
            'matching_scope' => SupplierOrderProfileFactoryData::itegraMatchingScope(),
            'policy_overrides' => [],
            'health_state' => 'unknown',
        ]);
    }

    /** @param array<string, mixed> $definition */
    private function activeProfile(
        string $slug,
        int $priority,
        array $definition,
    ): PurchaseOrderImportProfile {
        $profile = $this->draftProfile($slug, $priority);
        $version = PurchaseOrderImportProfileVersion::query()->create([
            'profile_id' => $profile->id,
            'version_number' => 1,
            'schema_version' => SupplierOrderProfileDefinitionValidator::SCHEMA_VERSION,
            'status' => PurchaseOrderImportProfileVersion::STATUS_ACTIVE,
            'definition' => $definition,
            'checksum' => StableJson::checksum($definition),
            'source' => 'test',
        ]);
        $profile->update([
            'active_version_id' => $version->id,
            'lifecycle_state' => PurchaseOrderImportProfile::STATE_ACTIVE,
        ]);

        return $profile->fresh();
    }

    private function protectedFixture(
        PurchaseOrderImportProfile $profile,
        PurchaseOrderImportProfileVersion $version,
    ): PurchaseOrderImportProfileFixture {
        $source = $this->sourceSnapshot();
        $expected = $this->expectedSubset();

        return PurchaseOrderImportProfileFixture::query()->create([
            'profile_id' => $profile->id,
            'profile_version_id' => $version->id,
            'name' => 'Itegra standard order confirmation',
            'fixture_type' => 'body',
            'is_protected' => true,
            'safe_source_snapshot' => $source,
            'expected_document' => $expected,
            'source_checksum' => StableJson::checksum($source),
            'expected_checksum' => StableJson::checksum($expected),
        ]);
    }

    /** @return array<string, mixed> */
    private function sourceSnapshot(): array
    {
        return [
            'schema_version' => 'storage.supplier_order_source.v1',
            'source' => 'email',
            'mailbox' => 'orders@nexum.test',
            'subject' => 'Takk for din ordre',
            'from' => ['name' => 'Itegra', 'email' => 'synthetic-fixture@itegra.no'],
            'to' => [['name' => 'Purchasing', 'email' => 'purchasing@example.invalid']],
            'cc' => [],
            'received_at' => '2026-08-05T09:30:00+02:00',
            'body_html' => '',
            'body_text' => <<<'TEXT'
Hei!

Takk for din ordre.

Ordresammendrag:
Ordrenr.: 9900000001 (Se ordrestatus)
Bestiller: Nexum Testbed
Betaling: Kort
Best. Ref:
PO. Ref:
Levering: Stykkgods NO

Nexum synthetic profile fixture
Varenr: (NX-SYN-1001)
1
100,00

Total varer
Frakt
Verdikode
Totalt eks. MVA:
100,00
25,00
0,00
125,00
TEXT,
            'attachments' => [],
            'trusted_auth' => [
                'authentication_passed' => true,
                'authenticated_supplier_identity' => 'itegra.no',
                'authenticated_supplier_domain' => 'itegra.no',
                'authserv_id' => 'mail.test',
                'spf' => 'pass',
                'dkim' => 'pass',
                'dmarc' => 'pass',
                'aligned' => true,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function expectedSubset(): array
    {
        return [
            'schema_version' => 'storage.supplier_order.v1',
            'document_type' => 'supplier_order_confirmation',
            'external_order_number' => '9900000001',
            'supplier' => ['name' => 'Itegra'],
            'currency' => 'NOK',
            'lines' => [[
                'supplier_sku' => 'NX-SYN-1001',
                'description' => 'Nexum synthetic profile fixture',
                'quantity' => 1,
                'line_total' => '100',
            ]],
            'totals' => [
                'goods_subtotal' => '100',
                'freight' => '25',
                'discount' => '0',
                'total_ex_tax' => '125',
            ],
        ];
    }

    /** @return array<string, int> */
    private function operationalCounts(): array
    {
        return [
            'vendors' => DB::table('vendors')->count(),
            'items' => DB::table('storage_items')->count(),
            'purchase_orders' => DB::table('storage_purchase_orders')->count(),
            'purchase_receipts' => DB::table('storage_purchase_receipts')->count(),
            'movements' => DB::table('storage_movements')->count(),
            'stock_units' => DB::table('storage_stock_units')->count(),
        ];
    }
}
