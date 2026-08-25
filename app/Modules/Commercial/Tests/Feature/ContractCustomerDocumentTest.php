<?php

namespace App\Modules\Commercial\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Actions\CaptureContractCustomerDocument;
use App\Modules\Commercial\Livewire\Tech\Contracts\ContractItemsEditor;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Support\ContractCustomerDocument;
use App\Modules\Commercial\Support\ContractLegacyDocumentReadiness;
use App\Modules\Commercial\Support\ContractTermSnapshotReadiness;
use App\Modules\System\Support\CompanyProfileSettings;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ContractCustomerDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        $this->tech->givePermissionTo([
            'commercial.contract_manage',
            'commercial.service_manage',
            'commercial.rate_manage',
        ]);

        app(CompanyProfileSettings::class)->update([
            'company_name' => 'Trønder Data',
            'legal_name' => 'Trønder Data AS',
            'organization_number' => '999888777',
            'address_line_1' => 'Teknologiveien 1',
            'postal_code' => '7600',
            'city' => 'Levanger',
            'country' => 'Norway',
        ]);
    }

    #[Test]
    public function customer_projection_has_six_plain_columns_exact_cadence_totals_and_explicit_deduplicated_rates(): void
    {
        $fixture = $this->richContractFixture();
        $projector = app(ContractCustomerDocument::class);
        $document = $projector->build(
            $fixture['contract']->fresh(),
            app(CompanyProfileSettings::class)->get(),
        );

        $this->assertSame([
            'service',
            'short_description',
            'scope',
            'unit_price',
            'billing',
            'total',
        ], array_keys($document['columns']));

        foreach ($document['lines'] as $line) {
            $this->assertSame(array_keys($document['columns']), array_keys($line));
        }

        $this->assertSame('Avtaleutkast', $document['document']['type']);
        $this->assertSame('Komplett IT-avtale for kunden.', $document['description']);
        $this->assertSame('01.09.2026', $document['dates']['start']['value']);
        $this->assertSame('Trønder Data AS', $document['parties']['supplier']['name']);
        $this->assertSame('999888777', $document['parties']['supplier']['organization_number']);
        $this->assertSame('Eksempel Kunde AS', $document['parties']['customer']['name']);
        $this->assertSame('987654321', $document['parties']['customer']['organization_number']);
        $this->assertFalse($document['approval']['accepted']);

        $edr = collect($document['lines'])->firstWhere('service', 'EDR endepunktsikring');
        $this->assertNotNull($edr);
        $this->assertSame('Endepunktsikring for kundens enheter.', $edr['short_description']);
        $this->assertSame('3 enheter', $edr['scope']);
        $this->assertSame('109.00', $edr['unit_price']['decimal']);
        $this->assertSame('109,00 kr', $edr['unit_price']['display']);
        $this->assertSame('327,00 kr', $edr['total']['display']);

        $included = collect($document['lines'])->firstWhere('service', 'Inkludert basisoppsett');
        $this->assertNotNull($included);
        $this->assertSame('Inkludert basisoppsett', $included['short_description']);
        $this->assertTrue($included['total']['included']);
        $this->assertSame('Inkludert', $included['total']['display']);

        $this->assertSame(387968, $document['totals']['monthly']['minor']);
        $this->assertSame('3879.68', $document['totals']['monthly']['decimal']);
        $this->assertSame('3 879,68 kr', $document['totals']['monthly']['display']);
        $this->assertSame('400.00', $document['totals']['quarterly']['decimal']);
        $this->assertSame('1200.00', $document['totals']['yearly']['decimal']);
        $this->assertSame('550.00', $document['totals']['one_time']['decimal']);

        $this->assertNotNull($document['rates']);
        $this->assertSame('Satser for arbeid utenfor avtalt omfang', $document['rates']['title']);
        $this->assertCount(2, $document['rates']['items']);
        $ratesJson = json_encode($document['rates']['items'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->assertSame(2, substr_count($ratesJson, 'Konsulenttime'));
        $this->assertStringContainsString('1 250,00 kr', $ratesJson);
        $this->assertStringContainsString('1 500,00 kr', $ratesJson);
        $this->assertStringNotContainsString('Intern kostsats', $ratesJson);

        $this->assertSame('Support og responstid', $document['support']['title']);
        $this->assertStringContainsString('fire timer', $document['support']['content']);
        $this->assertSame(
            range(1, count($document['appendices'])),
            array_column($document['appendices'], 'number'),
        );
        foreach ($document['appendices'] as $appendix) {
            $this->assertNotEmpty($appendix['version']);
            $this->assertNotEmpty($appendix['date']);
        }
        $this->assertSame(
            'Endepunktsikring & kontroll',
            $projector->plainText('<p>Endepunktsikring &amp; <strong>kontroll</strong></p>'),
        );

        $emptyRatesContract = $this->contract(['description' => 'Avtale uten kundesatser']);
        $this->item($emptyRatesContract, [
            'name' => 'Fast tjeneste',
            'customer_description' => 'Fast tjeneste uten tilleggssatser.',
            'unit_price' => '100.00',
        ]);
        $withoutRates = $projector->build(
            $emptyRatesContract->fresh(),
            app(CompanyProfileSettings::class)->get(),
        );
        $this->assertNull($withoutRates['rates']);
    }

    #[Test]
    public function captured_customer_document_is_not_rewritten_by_catalogue_line_or_company_changes(): void
    {
        $fixture = $this->richContractFixture();
        $contract = $fixture['contract'];
        $capture = app(CaptureContractCustomerDocument::class);
        $projector = app(ContractCustomerDocument::class);

        $captured = $capture->handle($contract, 'sent_contract');
        $contract->forceFill([
            'approval_status' => 'sent_contract',
            'sent_at' => now(),
        ])->saveQuietly();

        $this->assertSame($captured, $contract->fresh()->customer_document_snapshot);

        $fixture['service']->update([
            'name' => 'Endret katalognavn',
            'short_description' => 'Endret katalogtekst som ikke skal lekke inn.',
            'price_ex_vat' => '999.00',
        ]);
        $fixture['edr_item']->update([
            'name' => 'Operasjonelt endret navn',
            'customer_description' => 'Operasjonelt endret tekst.',
            'unit_price' => '999.00',
        ]);
        app(CompanyProfileSettings::class)->update([
            'company_name' => 'Endret merkenavn',
            'legal_name' => 'Endret Juridisk AS',
            'organization_number' => '111222333',
        ]);

        $resolved = $projector->resolve(
            $contract->fresh(),
            app(CompanyProfileSettings::class)->get(),
        );
        $liveRebuild = $projector->build(
            $contract->fresh(),
            app(CompanyProfileSettings::class)->get(),
        );

        $this->assertSame($captured, $resolved);
        $this->assertNotSame($captured, $liveRebuild);
        $this->assertSame('3 879,68 kr', $resolved['totals']['monthly']['display']);
        $this->assertSame(
            'Endepunktsikring for kundens enheter.',
            collect($resolved['lines'])->firstWhere('service', 'EDR endepunktsikring')['short_description'],
        );
        $this->assertStringContainsString(
            'Trønder Data AS',
            json_encode($resolved['parties'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
        $this->assertStringNotContainsString(
            'Endret Juridisk AS',
            json_encode($resolved['parties'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        $this->assertSame($captured, $capture->handle($contract->fresh(), 'won'));
        $this->assertSame($captured, $contract->fresh()->customer_document_snapshot);
    }

    #[Test]
    public function legacy_sent_contract_without_customer_document_fails_closed_without_reading_later_catalogue_text(): void
    {
        $fixture = $this->richContractFixture();
        $legacy = $this->contract([
            'approval_status' => 'draft',
            'description' => 'Historisk avtale uten kundedokumentsnapshot',
        ]);
        $this->item($legacy, [
            'service_id' => $fixture['service']->id,
            'name' => 'Historisk endepunkttjeneste',
            'customer_description' => null,
            'unit_price' => '250.00',
        ]);
        $legacy->forceFill([
            'approval_status' => 'sent_contract',
            'sent_at' => now(),
            'secure_token' => Str::random(64),
            'customer_document_snapshot' => null,
        ])->saveQuietly();
        $fixture['service']->update([
            'name' => 'Nytt katalognavn',
            'short_description' => 'Ny katalogtekst som ikke tilhører historikken.',
        ]);
        $legacy = $legacy->fresh();
        $exception = null;

        try {
            app(ContractCustomerDocument::class)->resolve($legacy);
        } catch (DomainException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(DomainException::class, $exception);
        $this->assertSame(
            app(ContractLegacyDocumentReadiness::class)->failureMessage(),
            $exception->getMessage(),
        );
        $this->get(route('contracts.public.view', $legacy->secure_token))->assertStatus(409);

        $preview = app(ContractCustomerDocument::class)->previewForInternalReview($legacy);
        $this->assertSame('Historisk endepunkttjeneste', $preview['lines'][0]['service']);
        $this->assertSame('Historisk endepunkttjeneste', $preview['lines'][0]['short_description']);
        $this->assertSame('250.00', $preview['lines'][0]['unit_price']['decimal']);
        $this->assertStringNotContainsString(
            'Ny katalogtekst',
            json_encode($preview, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
        $this->assertNull($legacy->fresh()->customer_document_snapshot);
    }

    #[Test]
    public function public_ui_api_and_pdf_template_use_the_same_snapshotted_customer_totals(): void
    {
        $fixture = $this->richContractFixture();
        $contract = $fixture['contract'];
        $captured = app(CaptureContractCustomerDocument::class)->handle($contract, 'sent_contract');
        $contract->forceFill([
            'approval_status' => 'sent_contract',
            'secure_token' => Str::random(64),
            'sent_at' => now(),
        ])->saveQuietly();
        $contract->refresh();

        $public = $this->get(route('contracts.public.view', $contract->secure_token));
        $public->assertOk()
            ->assertSee('<html lang="nb">', false)
            ->assertSee('Tjeneste')
            ->assertSee('Kort beskrivelse')
            ->assertSee('Omfang')
            ->assertSee('Enhetspris')
            ->assertSee('Fakturering')
            ->assertSee('Sum')
            ->assertSee('3 879,68 kr')
            ->assertDontSee('SKU')
            ->assertDontSee('Intern kostsats');

        Sanctum::actingAs($this->tech, ['commercial.read']);
        $api = $this->getJson(route('api.v1.commercial.contracts.show', $contract));
        $api->assertOk();
        $this->assertSame($captured['totals'], $api->json('data.customer_document.totals'));
        $this->assertSame($captured['lines'], $api->json('data.customer_document.lines'));
        $this->assertSame($captured['rates'], $api->json('data.customer_document.rates'));

        $pdfHtml = view('commercial::Tech.cs.contracts.pdf', [
            'contract' => $contract->load(['client', 'items.timeRates', 'termSnapshots']),
            'customerDocument' => $captured,
            'companyProfile' => app(CompanyProfileSettings::class)->get(),
        ])->render();
        $this->assertStringContainsString('3 879,68 kr', $pdfHtml);
        $this->assertStringContainsString('Kort beskrivelse', $pdfHtml);
        $this->assertStringNotContainsString('SKU', $pdfHtml);
        $this->assertStringNotContainsString('Intern kostsats', $pdfHtml);
        $this->assertSame(1, substr_count($pdfHtml, 'Support og responstid'));

        $pdf = $this->actingAs($this->tech)->get(route('tech.contracts.pdf', $contract));
        $pdf->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    #[Test]
    public function ui_and_api_reject_binding_after_end_and_api_rejects_sent_contract_changes(): void
    {
        $client = Client::factory()->create();
        $payload = [
            'client_id' => $client->id,
            'created_by' => $this->tech->id,
            'description' => 'Ugyldig datorekkefølge',
            'start_date' => '2026-09-01',
            'end_date' => '2026-12-31',
            'binding_end_date' => '2027-01-01',
        ];
        $message = 'Bindingstiden kan ikke slutte etter at avtalen er avsluttet.';

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.store'), $payload)
            ->assertSessionHasErrors(['binding_end_date' => $message]);

        Sanctum::actingAs($this->tech, ['commercial.create', 'commercial.update']);
        $this->postJson(route('api.v1.commercial.contracts.store'), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.binding_end_date.0', $message);

        $contract = $this->contract([
            'approval_status' => 'sent_contract',
            'description' => 'Låst kundesnapshot',
        ]);
        $this->patchJson(route('api.v1.commercial.contracts.update', $contract), [
            'description' => 'Skal ikke lagres',
        ])->assertStatus(409);
        $this->assertSame('Låst kundesnapshot', $contract->fresh()->description);
    }

    #[Test]
    public function editable_lines_can_sync_verified_catalogue_cadence_without_rewriting_sent_lines(): void
    {
        $unit = Units::query()->create(['name' => 'Endepunkt', 'short' => 'stk']);
        $service = Services::query()->create([
            'sku' => 'EDR-MONTHLY',
            'name' => 'Endepunktsikring',
            'unitId' => $unit->id,
            'status' => 'published',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => '109.00',
            'price_including_tax' => '136.25',
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);
        $contract = $this->contract(['description' => 'Utkast med feil periode']);
        $item = $this->item($contract, [
            'service_id' => $service->id,
            'name' => $service->name,
            'unit_price' => '109.00',
            'billing_interval' => 'yearly',
        ]);

        Livewire::actingAs($this->tech)
            ->test(ContractItemsEditor::class, ['contract' => $contract])
            ->call('syncBillingIntervalFromService', 0)
            ->assertSet('items.0.billing_interval', 'monthly');

        $this->assertSame('monthly', $item->fresh()->billing_interval);

        $contract->forceFill(['approval_status' => 'sent_contract'])->saveQuietly();
        $item->refresh()->forceFill(['billing_interval' => 'yearly'])->save();

        $lockedEditor = Livewire::actingAs($this->tech)
            ->test(ContractItemsEditor::class, ['contract' => $contract->fresh()]);
        $lockedEditor->instance()->isEditable = true;
        $exception = null;

        try {
            $lockedEditor->instance()->syncBillingIntervalFromService(0);
        } catch (HttpException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(HttpException::class, $exception);
        $this->assertSame(409, $exception->getStatusCode());
        $this->assertSame('yearly', $item->fresh()->billing_interval);
    }

    /**
     * Build a mixed-cadence contract that includes the exact requested EDR calculation.
     *
     * @return array{contract: Contracts, service: Services, edr_item: ContractItem}
     */
    private function richContractFixture(): array
    {
        $unit = Units::query()->create(['name' => 'Enhet', 'short' => 'stk']);
        $service = Services::query()->create([
            'sku' => 'EDR-ENDPOINT',
            'name' => 'EDR endepunktsikring',
            'unitId' => $unit->id,
            'status' => 'published',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => '109.00',
            'price_including_tax' => '136.25',
            'short_description' => '<p>Endepunktsikring for kundens enheter.</p>',
            'customer_unit_singular' => 'enhet',
            'customer_unit_plural' => 'enheter',
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);
        $contract = $this->contract([
            'description' => '<p>Komplett IT-avtale for kunden.</p>',
            'terms_snapshot' => 'Generelle vilkår for tjenestene.',
            'dpa_snapshot' => 'Databehandleravtale for behandlingen.',
            'legal_snapshot' => 'Juridiske vilkår for avtalen.',
            'sla_snapshot' => 'Support på hverdager med svar innen fire timer.',
            'general_snapshot' => 'Praktisk informasjon om leveransen.',
        ]);

        $platform = $this->item($contract, [
            'name' => 'Administrert plattform',
            'customer_description' => '<p>Administrert plattform og oppfølging.</p>',
            'unit_price' => '829.42',
            'quantity' => 4,
            'customer_unit_singular' => 'bruker',
            'customer_unit_plural' => 'brukere',
        ]);
        $this->item($contract, [
            'name' => 'E-postbeskyttelse',
            'customer_description' => 'Beskyttelse av e-postkontoer.',
            'unit_price' => '19.00',
            'quantity' => 11,
            'customer_unit_singular' => 'konto',
            'customer_unit_plural' => 'kontoer',
        ]);
        $edr = $this->item($contract, [
            'service_id' => $service->id,
            'name' => 'EDR endepunktsikring',
            'sku' => 'EDR-ENDPOINT',
            'customer_description' => '<p>Endepunktsikring for kundens enheter.</p>',
            'unit_price' => '109.00',
            'quantity' => 3,
            'customer_unit_singular' => 'enhet',
            'customer_unit_plural' => 'enheter',
        ]);
        $this->item($contract, [
            'name' => 'DNS-sikring',
            'customer_description' => 'Sikker DNS for virksomheten.',
            'unit_price' => '26.00',
        ]);
        $this->item($contract, [
            'name' => 'Årlig revisjon',
            'customer_description' => 'Årlig gjennomgang av avtalen.',
            'unit_price' => '1200.00',
            'billing_interval' => 'yearly',
        ]);
        $this->item($contract, [
            'name' => 'Kvartalsvis kontroll',
            'customer_description' => 'Kontroll hvert kvartal.',
            'unit_price' => '400.00',
            'billing_interval' => 'quarterly',
        ]);
        $this->item($contract, [
            'name' => 'Etablering',
            'customer_description' => 'Etablering av leveransen.',
            'unit_price' => '500.00',
            'setup_fee' => '50.00',
            'billing_interval' => 'one_time',
        ]);
        $this->item($contract, [
            'name' => 'Inkludert basisoppsett',
            'customer_description' => null,
            'unit_price' => '0.00',
        ]);

        $this->rate($platform, 'Konsulenttime', '1250.00', true, 10);
        $this->rate($edr, 'Konsulenttime', '1250.00', true, 10);
        $this->rate($edr, 'Konsulenttime', '1500.00', true, 20);
        $this->rate($edr, 'Intern kostsats', '300.00', false, 30);

        return [
            'contract' => $contract,
            'service' => $service,
            'edr_item' => $edr,
        ];
    }

    private function contract(array $overrides = []): Contracts
    {
        $client = Client::factory()->create([
            'name' => 'Eksempel Kunde AS',
            'org_no' => '987654321',
        ]);

        $contract = Contracts::query()->create(array_replace([
            'client_id' => $client->id,
            'description' => 'Kundeavtale',
            'start_date' => '2026-09-01',
            'end_date' => '2027-08-31',
            'binding_end_date' => '2027-06-30',
            'auto_renew' => true,
            'renewal_months' => 12,
            'approval_status' => 'draft',
            'created_by' => $this->tech->id,
        ], $overrides));

        app(ContractTermSnapshotReadiness::class)->markReviewed($contract, $this->tech->id);

        return $contract->fresh();
    }

    private function item(Contracts $contract, array $overrides): ContractItem
    {
        return ContractItem::query()->create(array_replace([
            'contract_id' => $contract->id,
            'service_id' => null,
            'name' => 'Tjeneste',
            'sku' => 'INTERNAL-SKU',
            'customer_description' => null,
            'unit_price' => '0.00',
            'quantity' => 1,
            'unit' => 'stk',
            'customer_unit_singular' => 'enhet',
            'customer_unit_plural' => 'enheter',
            'billing_interval' => 'monthly',
            'discount_value' => null,
            'discount_type' => null,
            'setup_fee' => '0.00',
            'uses_contract_default_sla' => true,
        ], $overrides));
    }

    private function rate(
        ContractItem $item,
        string $name,
        string $amount,
        bool $visible,
        int $sortOrder,
    ): void {
        $item->timeRates()->create([
            'name' => $name,
            'code' => Str::slug($name),
            'rate_type' => 'labor',
            'unit' => 'hour',
            'amount_ex_vat' => $amount,
            'currency' => 'NOK',
            'is_active' => true,
            'is_customer_visible' => $visible,
            'sort_order' => $sortOrder,
        ]);
    }
}
