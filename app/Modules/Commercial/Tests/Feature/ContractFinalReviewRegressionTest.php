<?php

namespace App\Modules\Commercial\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Commercial\Actions\BuildContractTermSnapshots;
use App\Modules\Commercial\Actions\CaptureContractCustomerDocument;
use App\Modules\Commercial\Actions\CaptureContractTermVersions;
use App\Modules\Commercial\Controllers\Api\V1\CommercialController;
use App\Modules\Commercial\Controllers\Tech\Contracts\ContractController;
use App\Modules\Commercial\Livewire\Tech\Contracts\ContractItemsEditor;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\Terms\terms as CommercialTerm;
use App\Modules\Commercial\Requests\ContractsRequest;
use App\Modules\Commercial\Services\LegalDocumentVersioning;
use App\Modules\Commercial\Support\ContractCustomerDocument;
use App\Modules\Commercial\Support\ContractDocumentReadiness;
use App\Modules\Commercial\Support\ContractLegacyDocumentReadiness;
use App\Modules\Commercial\Support\ContractPricing;
use App\Modules\Commercial\Support\ContractTermSnapshotReadiness;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Integration\Models\CloudFactory\Subscription;
use App\Modules\System\Support\CompanyProfileSettings;
use DomainException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use LogicException;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use UnexpectedValueException;

class ContractFinalReviewRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Tech']);
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        $this->tech->givePermissionTo([
            'commercial.contract_manage',
            'commercial.service_manage',
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
    public function customer_rates_keep_currency_in_display_and_deduplication_identity(): void
    {
        $contract = $this->contract();
        $item = $this->item($contract, null, [
            'name' => 'Administrert support',
            'customer_description' => 'Administrert support for kunden.',
        ]);

        $this->rate($item, 'Konsulenttime', '125.50', 'NOK');
        $this->rate($item, 'Konsulenttime', '125.50', 'EUR');

        $document = app(ContractCustomerDocument::class)->build($contract->fresh());
        $rates = collect($document['rates']['items'])->keyBy('currency');

        $this->assertCount(2, $rates);
        $this->assertSame(['EUR', 'NOK'], $rates->keys()->sort()->values()->all());
        $this->assertSame('125,50 kr / time', $rates->get('NOK')['display']);
        $this->assertSame('125,50 EUR / time', $rates->get('EUR')['display']);
        $this->assertStringNotContainsString('kr', $rates->get('EUR')['display']);
    }

    #[Test]
    public function customer_document_fingerprint_is_stable_across_opposite_loaded_item_and_rate_order(): void
    {
        $contract = $this->contract(['description' => 'Deterministisk attestasjon']);
        $firstItem = $this->item($contract, null, [
            'name' => 'Første stabile linje',
            'unit_price' => '100.00',
        ]);
        $secondItem = $this->item($contract, null, [
            'name' => 'Andre stabile linje',
            'unit_price' => '200.00',
        ]);
        $this->rate($firstItem, 'Zulu sats', '950.00', 'NOK');
        $this->rate($secondItem, 'Alfa sats', '850.00', 'NOK');
        $contract->forceFill([
            'approval_status' => 'sent_contract',
            'sent_at' => Carbon::parse('2026-08-24 14:30:00'),
            'customer_document_snapshot' => null,
        ])->saveQuietly();

        $ascending = $contract->fresh();
        $ascending->setRelation(
            'items',
            $ascending->items()
                ->with(['timeRates' => fn ($query) => $query->orderBy('id')])
                ->orderBy('id')
                ->get(),
        );
        $descending = $contract->fresh();
        $descending->setRelation(
            'items',
            $descending->items()
                ->with(['timeRates' => fn ($query) => $query->orderByDesc('id')])
                ->orderByDesc('id')
                ->get(),
        );
        $documents = app(ContractCustomerDocument::class);
        $ascendingDocument = $documents->previewForLegacyAttestation(
            $ascending,
            null,
            'Avtale',
        );
        $descendingDocument = $documents->previewForLegacyAttestation(
            $descending,
            null,
            'Avtale',
        );

        $this->assertSame($ascendingDocument, $descendingDocument);
        $this->assertSame(
            $documents->fingerprint($ascendingDocument),
            $documents->fingerprint($descendingDocument),
        );
        $this->assertSame([
            'Første stabile linje',
            'Andre stabile linje',
        ], array_column($ascendingDocument['lines'], 'service'));
        $this->assertSame([
            'Alfa sats',
            'Zulu sats',
        ], array_column($ascendingDocument['rates']['items'], 'name'));
        $this->assertSame('300.00', $ascendingDocument['totals']['monthly']['decimal']);
    }

    #[Test]
    public function contract_pricing_rejects_eur_and_preserves_the_exact_nok_total(): void
    {
        $pricing = app(ContractPricing::class);
        $exception = null;

        try {
            $pricing->calculateLine([
                'unit_price' => '109.00',
                'price_currency' => 'EUR',
                'quantity' => 3,
                'billing_interval' => 'monthly',
                'setup_fee' => '0.00',
            ]);
        } catch (InvalidArgumentException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertSame(
            'Contract sale currency must be NOK. Mixed or foreign-currency totals are not supported.',
            $exception->getMessage(),
        );

        $totals = $pricing->calculateTotals([
            ['unit_price' => '829.42', 'price_currency' => 'NOK', 'quantity' => 4, 'billing_interval' => 'monthly'],
            ['unit_price' => '19.00', 'price_currency' => 'NOK', 'quantity' => 11, 'billing_interval' => 'monthly'],
            ['unit_price' => '109.00', 'price_currency' => 'NOK', 'quantity' => 3, 'billing_interval' => 'monthly'],
            ['unit_price' => '26.00', 'price_currency' => 'NOK', 'quantity' => 1, 'billing_interval' => 'monthly'],
        ]);

        $this->assertSame([
            'minor' => 387968,
            'decimal' => '3879.68',
            'display' => '3 879,68 kr',
        ], $totals['monthly']);
    }

    #[Test]
    public function contract_item_and_livewire_reject_authoritative_foreign_service_currency_without_persisting_it(): void
    {
        $contract = $this->contract();
        $service = $this->service();
        $modelException = null;

        try {
            $this->item($contract, $service, [
                'unit_price' => '100.00',
                'price_currency' => 'EUR',
            ]);
        } catch (InvalidArgumentException $caught) {
            $modelException = $caught;
        }

        $this->assertInstanceOf(InvalidArgumentException::class, $modelException);
        $this->assertSame(
            'Contract sale currency must be NOK. Cost currency is stored separately.',
            $modelException->getMessage(),
        );
        $this->assertDatabaseMissing('contract_items', [
            'contract_id' => $contract->id,
            'price_currency' => 'EUR',
        ]);

        $item = $this->item($contract, $service, [
            'unit_price' => '100.00',
            'price_currency' => 'NOK',
        ]);
        $component = Livewire::actingAs($this->tech)
            ->test(ContractItemsEditor::class, ['contract' => $contract->fresh()]);
        $service->forceFill(['price_currency' => 'EUR'])->save();

        $component
            ->set('items.0.price_currency', 'NOK')
            ->set('items.0.unit_price', '250.00')
            ->call('saveItem', 0)
            ->assertHasErrors(['items.0.price_currency'])
            ->assertSet('items.0.price_currency', 'EUR');

        $this->assertSame('100.00', $item->fresh()->unit_price);
        $this->assertSame('NOK', $item->fresh()->price_currency);
        $this->assertDatabaseMissing('contract_items', [
            'contract_id' => $contract->id,
            'price_currency' => 'EUR',
        ]);
    }

    #[Test]
    public function api_pricing_for_sent_and_won_contracts_uses_the_immutable_customer_snapshot(): void
    {
        Sanctum::actingAs($this->tech, ['commercial.read']);

        foreach (['sent_contract', 'won'] as $status) {
            $contract = $this->contract([
                'description' => 'Immutable API pricing '.$status,
            ]);
            $item = $this->item($contract, null, [
                'name' => 'Snapshot service '.$status,
                'unit_price' => '100.00',
                'quantity' => 2,
            ]);
            $snapshot = app(CaptureContractCustomerDocument::class)->handle($contract, $status);

            $contract->forceFill([
                'approval_status' => $status,
                'sent_at' => now(),
                'accepted_at' => $status === 'won' ? now() : null,
                'accepted_by_name' => $status === 'won' ? 'Godkjent Kunde' : null,
            ])->saveQuietly();

            $item->forceFill(['unit_price' => '999.00'])->save();
            $this->assertSame('1998.00', $contract->fresh()->pricingTotals()['monthly']['decimal']);

            $response = $this->getJson(route('api.v1.commercial.contracts.show', $contract))
                ->assertOk();

            $expected = $snapshot['totals']['monthly']['decimal'];
            $this->assertSame('200.00', $expected);
            $response
                ->assertJsonPath('data.customer_document.totals.monthly.decimal', $expected)
                ->assertJsonPath('data.pricing.monthly.decimal', $expected)
                ->assertJsonPath('data.total_monthly_amount', $expected);
        }
    }

    #[Test]
    public function api_collection_keeps_blocked_legacy_rows_without_exposing_live_customer_data(): void
    {
        Sanctum::actingAs($this->tech, ['commercial.read']);

        $valid = $this->contract(['description' => 'Valid immutable API row']);
        $this->item($valid, null, ['unit_price' => '100.00']);
        $validSnapshot = app(CaptureContractCustomerDocument::class)
            ->handle($valid, 'sent_contract');
        $valid->forceFill([
            'approval_status' => 'sent_contract',
            'sent_at' => now(),
        ])->saveQuietly();

        $blocked = $this->contract(['description' => 'Blocked legacy API row']);
        $this->item($blocked, null, ['unit_price' => '999.00']);
        $blocked->forceFill([
            'approval_status' => 'sent_contract',
            'sent_at' => now(),
            'customer_document_snapshot' => null,
        ])->saveQuietly();

        $response = $this->getJson(route('api.v1.commercial.contracts.index', [
            'per_page' => 15,
        ]))->assertOk();
        $rows = collect($response->json('data'))->keyBy(
            fn (array $row): int => (int) $row['id'],
        );

        $this->assertCount(2, $rows);
        $validRow = $rows->get($valid->id);
        $this->assertSame('sent_contract', $validRow['approval_status']);
        $this->assertSame('100.00', $validRow['total_monthly_amount']);
        $this->assertSame($validSnapshot['totals'], $validRow['pricing']);
        $this->assertSame($validSnapshot['lines'], $validRow['customer_document']['lines']);
        $this->assertSame([
            'ready' => true,
            'code' => 'ready',
            'message' => null,
        ], $validRow['customer_document_readiness']);

        $blockedRow = $rows->get($blocked->id);
        $this->assertSame('Blocked legacy API row', $blockedRow['description']);
        $this->assertSame('sent_contract', $blockedRow['approval_status']);
        $this->assertNull($blockedRow['total_monthly_amount']);
        $this->assertNull($blockedRow['pricing']);
        $this->assertNull($blockedRow['customer_document']);
        $this->assertSame([
            'ready' => false,
            'code' => 'manual_verification_required',
            'message' => 'Kundedokumentet krever manuell verifisering; ingen live fallback ble returnert.',
        ], $blockedRow['customer_document_readiness']);
        $this->assertNull($blocked->fresh()->customer_document_snapshot);

        $this->getJson(route('api.v1.commercial.contracts.show', $blocked))
            ->assertStatus(409)
            ->assertJsonPath(
                'message',
                'Kundedokumentet krever manuell verifisering; ingen live fallback ble returnert.',
            );
        $this->assertNull($blocked->fresh()->customer_document_snapshot);
    }

    #[Test]
    public function livewire_rechecks_persisted_status_when_public_is_editable_is_tampered(): void
    {
        $service = $this->service();
        $contract = $this->contract(['approval_status' => 'sent_contract']);
        $item = $this->item($contract, $service, [
            'name' => 'Låst tjeneste',
            'unit_price' => '100.00',
        ]);

        $saveComponent = Livewire::actingAs($this->tech)
            ->test(ContractItemsEditor::class, ['contract' => $contract]);
        $saveComponent->instance()->isEditable = true;
        $saveComponent->instance()->items[0]['unit_price'] = '999.00';
        $saveException = null;

        try {
            $saveComponent->instance()->saveItem(0);
        } catch (HttpException $caught) {
            $saveException = $caught;
        }

        $this->assertInstanceOf(HttpException::class, $saveException);
        $this->assertSame(409, $saveException->getStatusCode());
        $this->assertSame('100.00', $item->fresh()->unit_price);

        $removeComponent = Livewire::actingAs($this->tech)
            ->test(ContractItemsEditor::class, ['contract' => $contract->fresh()]);
        $removeComponent->instance()->isEditable = true;
        $removeException = null;

        try {
            $removeComponent->instance()->removeItem(0);
        } catch (HttpException $caught) {
            $removeException = $caught;
        }

        $this->assertInstanceOf(HttpException::class, $removeException);
        $this->assertSame(409, $removeException->getStatusCode());
        $this->assertDatabaseHas('contract_items', [
            'id' => $item->id,
            'contract_id' => $contract->id,
            'unit_price' => 100,
        ]);
    }

    #[Test]
    public function livewire_never_updates_an_item_owned_by_another_contract(): void
    {
        $service = $this->service();
        $ownContract = $this->contract(['description' => 'Egen kontrakt']);
        $foreignContract = $this->contract(['description' => 'Annen kontrakt']);
        $ownItem = $this->item($ownContract, $service, [
            'name' => 'Egen linje',
            'unit_price' => '100.00',
        ]);
        $foreignItem = $this->item($foreignContract, $service, [
            'name' => 'Fremmed linje',
            'unit_price' => '500.00',
        ]);

        $exception = null;

        try {
            Livewire::actingAs($this->tech)
                ->test(ContractItemsEditor::class, ['contract' => $ownContract])
                ->set('items.0.id', $foreignItem->id)
                ->set('items.0.unit_price', '999.00')
                ->call('saveItem', 0);
        } catch (ModelNotFoundException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(ModelNotFoundException::class, $exception);
        $this->assertDatabaseHas('contract_items', [
            'id' => $foreignItem->id,
            'contract_id' => $foreignContract->id,
            'name' => 'Fremmed linje',
            'unit_price' => 500,
        ]);
        $this->assertDatabaseHas('contract_items', [
            'id' => $ownItem->id,
            'contract_id' => $ownContract->id,
            'name' => 'Egen linje',
            'unit_price' => 100,
        ]);
    }

    #[Test]
    public function livewire_rejects_unknown_cadence_and_negative_price_before_persisting(): void
    {
        $service = $this->service();
        $contract = $this->contract();
        $item = $this->item($contract, $service, [
            'name' => 'Validert linje',
            'unit_price' => '100.00',
            'billing_interval' => 'monthly',
        ]);

        Livewire::actingAs($this->tech)
            ->test(ContractItemsEditor::class, ['contract' => $contract])
            ->set('items.0.billing_interval', 'weekly')
            ->call('saveItem', 0)
            ->assertHasErrors(['items.0.billing_interval' => 'in'])
            ->assertSee('Rett feltene som er markert før kontraktlinjen lagres.')
            ->assertSee('—')
            ->assertDontSee('Månedlig beløp eks. mva.');
        $this->assertSame('monthly', $item->fresh()->billing_interval);

        Livewire::actingAs($this->tech)
            ->test(ContractItemsEditor::class, ['contract' => $contract->fresh()])
            ->set('items.0.unit_price', '-1.00')
            ->call('saveItem', 0)
            ->assertHasErrors(['items.0.unit_price' => 'min'])
            ->assertSee('Rett feltene som er markert før kontraktlinjen lagres.')
            ->assertSee('—')
            ->assertDontSee('Månedlig beløp eks. mva.');
        $this->assertSame('100.00', $item->fresh()->unit_price);
    }

    #[Test]
    public function stale_draft_model_cannot_mutate_metadata_terms_api_or_delete_after_persisted_send(): void
    {
        $contract = $this->contract([
            'description' => 'Original låst metadata',
            'terms_snapshot' => 'Originale låste vilkår',
        ]);
        $staleDraft = $contract->fresh();
        Contracts::query()->whereKey($contract->id)->update([
            'approval_status' => 'sent_contract',
            'sent_at' => now(),
        ]);
        $controller = app(ContractController::class);

        $metadataResponse = $controller->update(
            $this->validatedMetadataRequest($staleDraft, 'Ulovlig metadataendring'),
            $staleDraft,
        );
        $this->assertSame(
            'Only editable contract drafts can be changed.',
            $metadataResponse->getSession()->get('error'),
        );

        $termsResponse = $controller->termsUpdate(Request::create('/', 'POST', [
            'terms_snapshot' => 'Ulovlig vilkårsendring',
            'dpa_snapshot' => 'Ulovlig DPA-endring',
            'legal_snapshot' => 'Ulovlig juridisk endring',
            'sla_snapshot' => 'Ulovlig SLA-endring',
            'general_snapshot' => 'Ulovlig generell endring',
        ]), $staleDraft);
        $this->assertSame(
            'Accepted and sent contract terms are immutable.',
            $termsResponse->getSession()->get('error'),
        );

        $apiRequest = Request::create('/', 'PATCH', [
            'description' => 'Ulovlig API-endring',
            'start_date' => $staleDraft->start_date->toDateString(),
            'end_date' => $staleDraft->end_date->toDateString(),
            'binding_end_date' => $staleDraft->binding_end_date->toDateString(),
        ]);
        $apiRequest->setUserResolver(fn (): User => $this->tech);
        $apiException = null;

        try {
            app(CommercialController::class)->updateContract($apiRequest, $staleDraft);
        } catch (HttpException $caught) {
            $apiException = $caught;
        }

        $this->assertInstanceOf(HttpException::class, $apiException);
        $this->assertSame(409, $apiException->getStatusCode());

        $destroyResponse = $controller->destroy($staleDraft);
        $this->assertSame(
            'Only draft contracts can be deleted.',
            $destroyResponse->getSession()->get('error'),
        );
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'approval_status' => 'sent_contract',
            'description' => 'Original låst metadata',
            'terms_snapshot' => 'Originale låste vilkår',
        ]);
    }

    #[Test]
    public function direct_resend_blocks_legacy_or_wrong_status_without_changing_cc_or_sending_mail(): void
    {
        $this->mock(DefaultEmailAccountResolver::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('forScope');
        });

        $legacy = $this->contract([
            'description' => 'Legacy resend without immutable document',
            'cc_email' => 'original-legacy@example.test',
        ]);
        $legacy->client()->update(['billing_email' => 'delivery-legacy@example.test']);
        $this->item($legacy);
        $legacy->forceFill([
            'approval_status' => 'sent_contract',
            'sent_at' => now(),
            'secure_token' => str()->random(64),
            'customer_document_snapshot' => null,
        ])->saveQuietly();

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.resend', $legacy), [
                'cc_email' => 'tampered-legacy@example.test',
            ])
            ->assertSessionHas(
                'error',
                app(ContractLegacyDocumentReadiness::class)->failureMessage(),
            );
        $this->assertSame('original-legacy@example.test', $legacy->fresh()->cc_email);
        $this->assertNull($legacy->fresh()->customer_document_snapshot);

        $wrongStatus = $this->contract([
            'description' => 'Draft direct resend attempt',
            'cc_email' => 'original-draft@example.test',
            'secure_token' => str()->random(64),
        ]);
        $wrongStatus->client()->update(['billing_email' => 'delivery-draft@example.test']);

        $this->get(route('contracts.public.view', $wrongStatus->secure_token))
            ->assertNotFound();

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.resend', $wrongStatus), [
                'cc_email' => 'tampered-draft@example.test',
            ])
            ->assertSessionHas('error', 'Only a sent quote or agreement can be resent.');
        $this->assertSame('draft', $wrongStatus->fresh()->approval_status);
        $this->assertSame('original-draft@example.test', $wrongStatus->fresh()->cc_email);

        $withoutToken = $this->contract([
            'description' => 'Captured sent document without customer token',
            'cc_email' => 'original-no-token@example.test',
        ]);
        $withoutToken->client()->update(['billing_email' => 'delivery-no-token@example.test']);
        $this->item($withoutToken);
        app(CaptureContractCustomerDocument::class)->handle($withoutToken, 'sent_contract');
        $withoutToken->forceFill([
            'approval_status' => 'sent_contract',
            'sent_at' => now(),
            'secure_token' => null,
        ])->saveQuietly();
        $withoutToken = $withoutToken->fresh();

        $this->actingAs($this->tech)
            ->get(route('tech.contracts.show', $withoutToken))
            ->assertOk()
            ->assertDontSee('Offentlig kundelenke')
            ->assertDontSee('Send e-post på nytt');

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.resend', $withoutToken), [
                'cc_email' => 'tampered-no-token@example.test',
            ])
            ->assertSessionHas(
                'error',
                'Kundelenke mangler for dette historiske dokumentet. Ny offentlig tilgang må opprettes gjennom en separat, kontrollert utsending.',
            );
        $this->assertSame('original-no-token@example.test', $withoutToken->fresh()->cc_email);
        $this->assertIsArray($withoutToken->fresh()->customer_document_snapshot);

        foreach ([
            'missing' => null,
            'invalid' => 'not-an-email',
        ] as $case => $billingEmail) {
            $withoutRecipient = $this->contract([
                'description' => 'Captured resend with '.$case.' billing recipient',
                'cc_email' => 'original-'.$case.'-recipient@example.test',
            ]);
            $withoutRecipient->client()->update(['billing_email' => $billingEmail]);
            $this->item($withoutRecipient);
            app(CaptureContractCustomerDocument::class)->handle(
                $withoutRecipient,
                'sent_contract',
            );
            $withoutRecipient->forceFill([
                'approval_status' => 'sent_contract',
                'sent_at' => now(),
                'secure_token' => str()->random(64),
            ])->saveQuietly();
            $withoutRecipient = $withoutRecipient->fresh();

            $this->actingAs($this->tech)
                ->post(route('tech.contracts.resend', $withoutRecipient), [
                    'cc_email' => 'tampered-'.$case.'-recipient@example.test',
                ])
                ->assertSessionHas(
                    'error',
                    'Kundens faktura-e-post mangler eller er ugyldig. Ingen e-post ble sendt, og kopi-adressen ble ikke endret.',
                )
                ->assertSessionMissing('success');

            $this->assertSame(
                'original-'.$case.'-recipient@example.test',
                $withoutRecipient->fresh()->cc_email,
            );
            $this->assertIsArray($withoutRecipient->fresh()->customer_document_snapshot);
        }
    }

    #[Test]
    public function send_rotates_dormant_customer_tokens_while_manual_approval_preserves_only_sent_links(): void
    {
        $oldToken = str()->random(64);
        $contract = $this->contract([
            'description' => 'Kundebytte før ny utsending',
            'secure_token' => $oldToken,
        ]);
        $oldClient = $contract->client;
        $this->item($contract);
        $newClient = Client::factory()->create([
            'name' => 'Ny Tokenkunde AS',
            'org_no' => '123123123',
            'billing_email' => null,
        ]);
        $contract->forceFill(['client_id' => $newClient->id])->saveQuietly();

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.send-contract', $contract), [
                'cc_email' => 'sent-copy@example.test',
            ])
            ->assertSessionHas('success', 'Contract sent as Binding Contract successfully.');
        $sent = $contract->fresh();
        $sentToken = $sent->secure_token;

        $this->assertSame('sent_contract', $sent->approval_status);
        $this->assertIsString($sentToken);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{64}\z/D', $sentToken);
        $this->assertNotSame($oldToken, $sentToken);
        $this->assertSame(
            'Ny Tokenkunde AS',
            data_get($sent->customer_document_snapshot, 'parties.customer.name'),
        );
        $this->get(route('contracts.public.view', $oldToken))->assertNotFound();
        $this->get(route('contracts.public.view', $sentToken))
            ->assertOk()
            ->assertSee('Ny Tokenkunde AS')
            ->assertDontSee($oldClient->name);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.resend', $sent), [
                'cc_email' => 'tampered-resend@example.test',
            ])
            ->assertSessionHas(
                'error',
                'Kundens faktura-e-post mangler eller er ugyldig. Ingen e-post ble sendt, og kopi-adressen ble ikke endret.',
            );
        $this->assertSame($sentToken, $sent->fresh()->secure_token);
        $this->assertSame('sent-copy@example.test', $sent->fresh()->cc_email);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.approve-manual', $sent))
            ->assertSessionHas('success', 'Contract manually approved and marked as Won.');
        $approvedSent = $sent->fresh();

        $this->assertSame('won', $approvedSent->approval_status);
        $this->assertSame($sentToken, $approvedSent->secure_token);
        $this->get(route('contracts.public.view', $sentToken))->assertOk();

        $dormantDraftToken = str()->random(64);
        $manualDraft = $this->contract([
            'description' => 'Direkte godkjent utkast med dormant token',
            'secure_token' => $dormantDraftToken,
        ]);
        $this->item($manualDraft);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.approve-manual', $manualDraft))
            ->assertSessionHas('success', 'Contract manually approved and marked as Won.');
        $approvedDraft = $manualDraft->fresh();

        $this->assertSame('won', $approvedDraft->approval_status);
        $this->assertNull($approvedDraft->secure_token);
        $this->assertIsArray($approvedDraft->customer_document_snapshot);
        $this->get(route('contracts.public.view', $dormantDraftToken))->assertNotFound();
    }

    #[Test]
    public function terms_get_is_preview_only_while_post_refresh_and_manual_save_are_reviewed_writes(): void
    {
        $service = $this->service();
        $term = CommercialTerm::query()->create([
            'name' => 'Preview-katalogvilkår',
            'type' => 'terms',
            'content' => 'Generert tekst som bare skal forhåndsvises på GET.',
            'origin' => 'nexum',
        ]);
        $service->serviceTerms()->attach($term->id);
        $contract = $this->contract([
            'description' => 'Avtale med eksplisitt vilkårslagring',
            'terms_snapshot' => 'Opprinnelig innhold brukt til komplett testdokument.',
        ]);
        $this->item($contract, $service, [
            'name' => $service->name,
            'sku' => $service->sku,
        ]);
        $storedSnapshot = app(ContractCustomerDocument::class)->build($contract->fresh());
        $originalMetadata = ['preview_sentinel' => 'skal-bevares'];
        $contract->forceFill([
            'terms_snapshot' => null,
            'approval_metadata' => $originalMetadata,
            'customer_document_snapshot' => $storedSnapshot,
        ])->saveQuietly();

        $this->actingAs($this->tech)
            ->get(route('tech.contracts.terms', $contract))
            ->assertOk()
            ->assertViewHas('hasGeneratedPreview', true)
            ->assertSee('Generert tekst som bare skal forhåndsvises på GET.');
        $afterGet = $contract->fresh();

        $this->assertNull($afterGet->terms_snapshot);
        $this->assertSame($originalMetadata, $afterGet->approval_metadata);
        $this->assertSame($storedSnapshot, $afterGet->customer_document_snapshot);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.terms.refresh', $contract))
            ->assertRedirect(route('tech.contracts.terms', $contract))
            ->assertSessionHas('success', 'Contract terms refreshed and snapshotted successfully.');
        $refreshed = $contract->fresh();

        $this->assertStringContainsString(
            'Generert tekst som bare skal forhåndsvises på GET.',
            $refreshed->terms_snapshot,
        );
        $this->assertNull($refreshed->customer_document_snapshot);
        $this->assertTrue(app(ContractTermSnapshotReadiness::class)->isCurrent($refreshed));
        $this->assertSame(
            $this->tech->id,
            data_get($refreshed->approval_metadata, 'customer_document_terms.reviewed_by_user_id'),
        );

        $manualText = 'Eksplisitt manuelt lagret kontraktstekst.';
        $this->actingAs($this->tech)
            ->post(route('tech.contracts.terms.update', $contract), [
                'terms_snapshot' => $manualText,
                'dpa_snapshot' => null,
                'legal_snapshot' => null,
                'sla_snapshot' => null,
                'general_snapshot' => null,
            ])
            ->assertSessionHas('success', 'Contract terms updated and snapshotted successfully.');
        $manual = $contract->fresh();

        $this->assertSame($manualText, $manual->terms_snapshot);
        $this->assertNull($manual->customer_document_snapshot);
        $this->assertTrue(app(ContractTermSnapshotReadiness::class)->isCurrent($manual));
    }

    #[Test]
    public function manual_sla_snapshot_survives_line_edit_while_term_source_readiness_becomes_stale(): void
    {
        $serviceA = $this->service();
        $serviceB = $this->service();
        $termA = CommercialTerm::query()->create([
            'name' => 'Linjevilkår A',
            'type' => 'terms',
            'content' => 'Vilkår fra opprinnelig tjeneste.',
            'origin' => 'nexum',
        ]);
        $termB = CommercialTerm::query()->create([
            'name' => 'Linjevilkår B',
            'type' => 'terms',
            'content' => 'Vilkår fra ny tjeneste.',
            'origin' => 'nexum',
        ]);
        $serviceA->serviceTerms()->attach($termA->id);
        $serviceB->serviceTerms()->attach($termB->id);
        $manualSla = 'Manuelt avtalt SLA som ikke må overskrives av linjeredigering.';
        $contract = $this->contract([
            'terms_snapshot' => null,
            'sla_snapshot' => $manualSla,
        ]);
        $contract->client()->update(['billing_email' => null]);
        $item = $this->item($contract, $serviceA, [
            'name' => $serviceA->name,
            'sku' => $serviceA->sku,
        ]);
        $generatedTerms = app(BuildContractTermSnapshots::class)
            ->handle($contract)['terms_snapshot'];

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.terms.update', $contract), [
                'terms_snapshot' => $generatedTerms,
                'dpa_snapshot' => null,
                'legal_snapshot' => null,
                'sla_snapshot' => $manualSla,
                'general_snapshot' => null,
            ])
            ->assertSessionHas('success', 'Contract terms updated and snapshotted successfully.');
        $reviewed = $contract->fresh();
        $readiness = app(ContractTermSnapshotReadiness::class);
        $this->assertTrue($readiness->isCurrent($reviewed));

        Livewire::actingAs($this->tech)
            ->test(ContractItemsEditor::class, ['contract' => $reviewed])
            ->set('items.0.service_id', $serviceB->id)
            ->assertHasNoErrors();
        $changed = $contract->fresh();

        $this->assertSame($serviceB->id, $item->fresh()->service_id);
        $this->assertSame($manualSla, $changed->sla_snapshot);
        $this->assertSame($generatedTerms, $changed->terms_snapshot);
        $this->assertFalse($readiness->isCurrent($changed));
        $this->actingAs($this->tech)
            ->post(route('tech.contracts.send-contract', $contract))
            ->assertSessionHas('error', $readiness->failureMessage());
        $this->assertSame('draft', $contract->fresh()->approval_status);
        $this->assertNull($contract->fresh()->customer_document_snapshot);
    }

    #[Test]
    public function changing_service_term_sources_blocks_send_until_terms_are_refreshed_and_reviewed(): void
    {
        $serviceA = $this->service();
        $serviceB = $this->service();
        $termA = CommercialTerm::query()->create([
            'name' => 'Vilkår for tjeneste A',
            'type' => 'terms',
            'content' => 'Kundevilkår fra tjeneste A.',
            'origin' => 'nexum',
        ]);
        $termB = CommercialTerm::query()->create([
            'name' => 'Vilkår for tjeneste B',
            'type' => 'terms',
            'content' => 'Kundevilkår fra tjeneste B.',
            'origin' => 'nexum',
        ]);
        $serviceA->serviceTerms()->attach($termA->id);
        $serviceB->serviceTerms()->attach($termB->id);
        $contract = $this->contract([
            'description' => 'Avtale med kildekontroll for vilkår',
            'terms_snapshot' => null,
        ]);
        $contract->client()->update(['billing_email' => null]);
        $item = $this->item($contract, $serviceA, [
            'name' => $serviceA->name,
            'sku' => $serviceA->sku,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.terms.refresh', $contract))
            ->assertRedirect(route('tech.contracts.terms', $contract))
            ->assertSessionHas('success', 'Contract terms refreshed and snapshotted successfully.');
        $reviewed = $contract->fresh();
        $this->assertStringContainsString('Kundevilkår fra tjeneste A.', $reviewed->terms_snapshot);
        $this->assertTrue(app(ContractTermSnapshotReadiness::class)->isCurrent($reviewed));
        $this->assertNotEmpty(data_get(
            $reviewed->approval_metadata,
            'customer_document_terms.source_fingerprint',
        ));

        $item->forceFill(['service_id' => $serviceB->id])->save();
        $failureMessage = 'Vilkårene samsvarer ikke med tjenestene og versjonene som skal vedlegges. Oppdater eller lagre vilkårene etter gjennomgang før utsending.';

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.send-contract', $contract))
            ->assertSessionHas('error', $failureMessage);
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'approval_status' => 'draft',
            'customer_document_snapshot' => null,
        ]);

        $beforePreview = $contract->fresh();
        $this->actingAs($this->tech)
            ->get(route('tech.contracts.terms', $contract))
            ->assertOk()
            ->assertSee('Kundevilkår fra tjeneste A.')
            ->assertDontSee('Kundevilkår fra tjeneste B.');
        $afterPreview = $contract->fresh();
        $this->assertSame($beforePreview->terms_snapshot, $afterPreview->terms_snapshot);
        $this->assertSame($beforePreview->approval_metadata, $afterPreview->approval_metadata);
        $this->assertSame($beforePreview->customer_document_snapshot, $afterPreview->customer_document_snapshot);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.terms.refresh', $contract))
            ->assertRedirect(route('tech.contracts.terms', $contract))
            ->assertSessionHas('success', 'Contract terms refreshed and snapshotted successfully.');
        $refreshed = $contract->fresh();
        $this->assertStringContainsString('Kundevilkår fra tjeneste B.', $refreshed->terms_snapshot);
        $this->assertStringNotContainsString('Kundevilkår fra tjeneste A.', $refreshed->terms_snapshot);
        $this->assertTrue(app(ContractTermSnapshotReadiness::class)->isCurrent($refreshed));

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.send-contract', $contract))
            ->assertSessionHas('success', 'Contract sent as Binding Contract successfully.');
        $this->assertSame('sent_contract', $contract->fresh()->approval_status);
        $this->assertNotNull($contract->fresh()->customer_document_snapshot);
    }

    #[Test]
    public function removing_all_catalogue_terms_still_requires_explicit_review_before_send(): void
    {
        $serviceWithTerms = $this->service();
        $serviceWithoutTerms = $this->service();
        $term = CommercialTerm::query()->create([
            'name' => 'Katalogvilkår som fjernes',
            'type' => 'terms',
            'content' => 'Vilkår som bare gjelder den første tjenesten.',
            'origin' => 'nexum',
        ]);
        $serviceWithTerms->serviceTerms()->attach($term->id);
        $contract = $this->contract([
            'description' => 'Avtale som bytter til tjeneste uten katalogvilkår',
            'terms_snapshot' => null,
        ]);
        $contract->client()->update(['billing_email' => null]);
        $item = $this->item($contract, $serviceWithTerms, [
            'name' => $serviceWithTerms->name,
            'sku' => $serviceWithTerms->sku,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.terms.refresh', $contract))
            ->assertRedirect(route('tech.contracts.terms', $contract))
            ->assertSessionHas('success', 'Contract terms refreshed and snapshotted successfully.');
        $this->assertStringContainsString(
            'Vilkår som bare gjelder den første tjenesten.',
            $contract->fresh()->terms_snapshot,
        );

        $item->forceFill(['service_id' => $serviceWithoutTerms->id])->save();
        $failureMessage = 'Vilkårene samsvarer ikke med tjenestene og versjonene som skal vedlegges. Oppdater eller lagre vilkårene etter gjennomgang før utsending.';

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.send-contract', $contract))
            ->assertSessionHas('error', $failureMessage);
        $this->assertSame('draft', $contract->fresh()->approval_status);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.terms.update', $contract), [
                'terms_snapshot' => 'Manuelt gjennomgåtte vilkår for tjenesten uten katalogvilkår.',
                'dpa_snapshot' => null,
                'legal_snapshot' => null,
                'sla_snapshot' => null,
                'general_snapshot' => null,
            ])
            ->assertSessionHas('success', 'Contract terms updated and snapshotted successfully.');
        $reviewed = $contract->fresh();
        $this->assertTrue(app(ContractTermSnapshotReadiness::class)->isCurrent($reviewed));
        $this->assertSame(
            'Manuelt gjennomgåtte vilkår for tjenesten uten katalogvilkår.',
            $reviewed->terms_snapshot,
        );

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.send-contract', $contract))
            ->assertSessionHas('success', 'Contract sent as Binding Contract successfully.');
        $this->assertSame('sent_contract', $contract->fresh()->approval_status);
    }

    #[Test]
    public function manually_reviewed_terms_use_a_contract_version_instead_of_the_catalogue_version(): void
    {
        $service = $this->service();
        $term = CommercialTerm::query()->create([
            'name' => 'Katalogvilkår A',
            'type' => 'terms',
            'content' => 'Katalogtekst A.',
            'origin' => 'nexum',
        ]);
        $catalogueVersion = app(LegalDocumentVersioning::class)->record($term, [
            'name' => 'Katalogvilkår A',
            'type' => 'terms',
            'version_label' => 'v1',
            'content' => 'Katalogtekst A.',
            'effective_at' => now()->subMonth()->toDateString(),
        ]);
        $service->serviceTerms()->attach($term->id);
        $contract = $this->contract([
            'description' => 'Avtale med manuelt gjennomgåtte vilkår',
            'terms_snapshot' => null,
        ]);
        $contract->client()->update(['billing_email' => null]);
        $this->item($contract, $service, [
            'name' => $service->name,
            'sku' => $service->sku,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.terms.refresh', $contract))
            ->assertRedirect(route('tech.contracts.terms', $contract))
            ->assertSessionHas('success', 'Contract terms refreshed and snapshotted successfully.');
        $readiness = app(ContractTermSnapshotReadiness::class);
        $generated = $contract->fresh();
        $this->assertStringContainsString(
            'Katalogtekst A.',
            $generated->terms_snapshot,
        );
        $this->assertTrue($readiness->isCurrent($generated));

        $generated->forceFill([
            'terms_snapshot' => 'Direkte endret tekst uten ny gjennomgang.',
        ])->save();
        $tampered = $contract->fresh();

        $this->assertFalse($readiness->isCurrent($tampered));
        $this->actingAs($this->tech)
            ->post(route('tech.contracts.send-contract', $contract))
            ->assertSessionHas('error', $readiness->failureMessage());
        $this->assertSame('draft', $contract->fresh()->approval_status);
        $this->assertNull($contract->fresh()->customer_document_snapshot);

        $manualText = 'Manuelt gjennomgått kontraktstekst B.';
        $this->actingAs($this->tech)
            ->post(route('tech.contracts.terms.update', $contract), [
                'terms_snapshot' => $manualText,
                'dpa_snapshot' => null,
                'legal_snapshot' => null,
                'sla_snapshot' => null,
                'general_snapshot' => null,
            ])
            ->assertSessionHas('success', 'Contract terms updated and snapshotted successfully.');
        $reviewedManual = $contract->fresh();

        $this->assertTrue($readiness->isCurrent($reviewedManual));
        $this->assertSame($manualText, $reviewedManual->terms_snapshot);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.send-contract', $contract))
            ->assertSessionHas('success', 'Contract sent as Binding Contract successfully.');
        $sent = $contract->fresh();

        $this->assertSame('sent_contract', $sent->approval_status);
        $this->assertDatabaseHas('contract_term_snapshots', [
            'contract_id' => $sent->id,
            'term_id' => $term->id,
            'term_version_id' => $catalogueVersion->id,
            'version_label' => 'v1',
            'content' => 'Katalogtekst A.',
        ]);
        $this->assertDatabaseHas('contract_term_snapshots', [
            'contract_id' => $sent->id,
            'term_id' => null,
            'term_version_id' => null,
            'version_label' => '1 (kontraktsspesifikk)',
            'content' => $manualText,
        ]);

        $document = app(ContractCustomerDocument::class)->resolve($sent);
        $appendix = collect($document['appendices'])
            ->firstWhere('title', 'Alminnelige avtalevilkår');

        $this->assertIsArray($appendix);
        $this->assertSame($manualText, $appendix['content']);
        $this->assertSame('1 (kontraktsspesifikk)', $appendix['version']);
        $this->assertNotSame('v1', $appendix['version']);
    }

    #[Test]
    public function unversioned_provider_terms_are_customer_facing_as_not_versioned_in_web_and_pdf(): void
    {
        $service = $this->service();
        $term = CommercialTerm::query()->create([
            'name' => 'Leverandørvilkår uten versjonsnummer',
            'type' => 'terms',
            'content' => 'Leverandørtekst uten offentlig versjonsnummer.',
            'origin' => 'provider',
        ]);
        $version = app(LegalDocumentVersioning::class)->record($term, [
            'name' => $term->name,
            'type' => 'terms',
            'content' => $term->content,
        ]);
        $this->assertSame('Unversioned', $version->version_label);
        $service->serviceTerms()->attach($term->id);

        $contract = $this->contract([
            'description' => 'Avtale med ikke-versjonerte leverandørvilkår',
            'terms_snapshot' => null,
        ]);
        $this->item($contract, $service, [
            'name' => $service->name,
            'sku' => $service->sku,
        ]);

        $generatedTerms = app(BuildContractTermSnapshots::class)->handle($contract);
        $contract->forceFill([
            'terms_snapshot' => $generatedTerms['terms_snapshot'],
        ])->save();
        app(ContractTermSnapshotReadiness::class)->markReviewed($contract, $this->tech->id);
        app(CaptureContractTermVersions::class)->replace($contract->fresh());
        $contract = $contract->fresh();
        $this->assertDatabaseHas('contract_term_snapshots', [
            'contract_id' => $contract->id,
            'term_id' => $term->id,
            'term_version_id' => $version->id,
            'version_label' => 'Unversioned',
        ]);
        $snapshot = app(CaptureContractCustomerDocument::class)->handle(
            $contract,
            'sent_contract',
        );
        $contract->forceFill([
            'approval_status' => 'sent_contract',
            'sent_at' => now(),
            'secure_token' => str()->random(64),
        ])->saveQuietly();
        $appendix = collect($snapshot['appendices'])
            ->firstWhere('title', 'Alminnelige avtalevilkår');

        $this->assertIsArray($appendix);
        $this->assertSame('ikke versjonert', $appendix['version']);
        $this->assertStringNotContainsString('Unversioned', json_encode(
            $snapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        ));

        $this->get(route('contracts.public.view', $contract->secure_token))
            ->assertOk()
            ->assertSee('ikke versjonert')
            ->assertDontSee('Unversioned');

        $pdf = $this->actingAs($this->tech)
            ->get(route('tech.contracts.pdf', $contract))
            ->assertOk();
        $content = (string) $pdf->getContent();

        $this->assertPdfContainsText($content, 'ikke versjonert');
        $this->assertFalse($this->pdfContainsText($content, 'Unversioned'));
    }

    #[Test]
    public function legacy_sent_terms_without_review_metadata_require_an_exact_current_generated_match(): void
    {
        $service = $this->service();
        $term = CommercialTerm::query()->create([
            'name' => 'Legacy katalogvilkår',
            'type' => 'terms',
            'content' => 'Gjeldende generert legacytekst.',
            'origin' => 'nexum',
        ]);
        app(LegalDocumentVersioning::class)->record($term, [
            'name' => 'Legacy katalogvilkår',
            'type' => 'terms',
            'version_label' => 'v1',
            'content' => 'Gjeldende generert legacytekst.',
        ]);
        $service->serviceTerms()->attach($term->id);
        $builder = app(BuildContractTermSnapshots::class);
        $readiness = app(ContractTermSnapshotReadiness::class);

        $matching = $this->contract(['terms_snapshot' => null]);
        $this->item($matching, $service, [
            'name' => $service->name,
            'sku' => $service->sku,
        ]);
        $currentGeneratedText = $builder->handle($matching)['terms_snapshot'];
        $this->assertStringContainsString('(versjon v1)', $currentGeneratedText);
        $matching->forceFill([
            'approval_status' => 'sent_contract',
            'approval_metadata' => null,
            'terms_snapshot' => $currentGeneratedText,
            'sent_at' => now(),
            'customer_document_snapshot' => null,
        ])->save();
        $matching = $matching->fresh();

        $this->assertNull($matching->approval_metadata);
        $this->assertTrue($readiness->isCurrent($matching));
        $this->assertNull($matching->customer_document_snapshot);

        $historical = $this->contract(['terms_snapshot' => null]);
        $this->item($historical, $service, [
            'name' => $service->name,
            'sku' => $service->sku,
        ]);
        $historicalGeneratedText = str_replace(
            ' (versjon v1)',
            '',
            $builder->handle($historical)['terms_snapshot'],
        );
        $this->assertStringNotContainsString('(versjon v1)', $historicalGeneratedText);
        $historical->forceFill([
            'approval_status' => 'sent_contract',
            'approval_metadata' => null,
            'terms_snapshot' => $historicalGeneratedText,
            'sent_at' => now(),
            'customer_document_snapshot' => null,
        ])->save();
        $historical = $historical->fresh();

        $this->assertTrue($readiness->isCurrent($historical));
        $this->assertNull($historical->customer_document_snapshot);

        $sourceDrift = $this->contract(['terms_snapshot' => null]);
        $this->item($sourceDrift, $service, [
            'name' => $service->name,
            'sku' => $service->sku,
        ]);
        $sourceDrift->forceFill([
            'approval_status' => 'sent_contract',
            'approval_metadata' => null,
            'terms_snapshot' => str_replace(
                ' (versjon v1)',
                '',
                $builder->handle($sourceDrift)['terms_snapshot'],
            ),
            'sent_at' => now(),
            'customer_document_snapshot' => null,
        ])->save();
        app(LegalDocumentVersioning::class)->record($term, [
            'name' => 'Legacy katalogvilkår',
            'type' => 'terms',
            'version_label' => 'v2',
            'content' => 'Ny katalogtekst som ikke samsvarer med sendt dokument.',
        ]);
        $sourceDrift = $sourceDrift->fresh();

        $this->assertFalse($readiness->isCurrent($sourceDrift));
        $this->assertNull($sourceDrift->fresh()->customer_document_snapshot);
    }

    #[Test]
    public function legacy_sent_terms_fail_closed_when_catalogue_sources_are_removed(): void
    {
        $builder = app(BuildContractTermSnapshots::class);
        $readiness = app(ContractTermSnapshotReadiness::class);

        foreach (['all-sources' => false, 'mixed-sources' => true] as $case => $keepSecondSource) {
            $service = $this->service();
            $firstTerm = CommercialTerm::query()->create([
                'name' => 'Legacy vilkår A '.$case,
                'type' => 'terms',
                'content' => 'Historisk kilde A for '.$case.'.',
                'origin' => 'nexum',
            ]);
            app(LegalDocumentVersioning::class)->record($firstTerm, [
                'name' => $firstTerm->name,
                'type' => 'terms',
                'version_label' => 'v1',
                'content' => $firstTerm->content,
            ]);
            $secondTerm = CommercialTerm::query()->create([
                'name' => 'Legacy vilkår B '.$case,
                'type' => 'terms',
                'content' => 'Historisk kilde B for '.$case.'.',
                'origin' => 'nexum',
            ]);
            app(LegalDocumentVersioning::class)->record($secondTerm, [
                'name' => $secondTerm->name,
                'type' => 'terms',
                'version_label' => 'v1',
                'content' => $secondTerm->content,
            ]);
            $service->serviceTerms()->attach([$firstTerm->id, $secondTerm->id]);

            $contract = $this->contract(['terms_snapshot' => null]);
            $this->item($contract, $service, [
                'name' => $service->name,
                'sku' => $service->sku,
            ]);
            $sentTerms = $builder->handle($contract)['terms_snapshot'];
            $contract->forceFill([
                'approval_status' => 'sent_contract',
                'approval_metadata' => null,
                'terms_snapshot' => $sentTerms,
                'sent_at' => now(),
                'customer_document_snapshot' => null,
            ])->save();
            $contract = $contract->fresh();

            $this->assertTrue($readiness->isCurrent($contract), $case.' starts as an exact legacy match.');

            $service->serviceTerms()->detach(
                $keepSecondSource ? [$firstTerm->id] : [$firstTerm->id, $secondTerm->id],
            );
            $stale = $contract->fresh();

            $this->assertFalse($readiness->isCurrent($stale));
            $this->assertSame($sentTerms, $stale->terms_snapshot);

            $exception = null;

            try {
                app(CaptureContractCustomerDocument::class)->handle($stale, 'sent_contract');
            } catch (DomainException $caught) {
                $exception = $caught;
            }

            $this->assertInstanceOf(DomainException::class, $exception);
            $this->assertSame($readiness->failureMessage(), $exception->getMessage());
            $this->assertNull($stale->fresh()->customer_document_snapshot);

            $this->actingAs($this->tech)
                ->post(route('tech.contracts.approve-manual', $stale))
                ->assertSessionHas(
                    'error',
                    app(ContractLegacyDocumentReadiness::class)->failureMessage(),
                );
            $unchanged = $stale->fresh();

            $this->assertSame('sent_contract', $unchanged->approval_status);
            $this->assertSame($sentTerms, $unchanged->terms_snapshot);
            $this->assertNull($unchanged->customer_document_snapshot);
        }
    }

    #[Test]
    public function incomplete_editable_draft_cannot_be_captured_or_manually_approved(): void
    {
        $contract = $this->contract([
            'description' => 'Ufullstendig utkast',
            'terms_snapshot' => null,
        ]);
        $captureException = null;

        try {
            app(CaptureContractCustomerDocument::class)->handle($contract, 'won');
        } catch (DomainException $caught) {
            $captureException = $caught;
        }

        $this->assertInstanceOf(DomainException::class, $captureException);
        $this->assertSame(
            'Kundedokumentet kan ikke fryses før kontrakten har tjenester, vilkår og en gyldig avtaleperiode.',
            $captureException->getMessage(),
        );
        $this->assertNull($contract->fresh()->customer_document_snapshot);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.approve-manual', $contract))
            ->assertSessionHas(
                'error',
                'Kontrakten må ha tjenester, vilkår og gyldig avtalestart før manuell godkjenning.',
            );
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'approval_status' => 'draft',
            'customer_document_snapshot' => null,
        ]);
    }

    #[Test]
    public function preexisting_binding_end_after_contract_end_blocks_send_and_capture(): void
    {
        $contract = $this->contract([
            'description' => 'Ugyldig lagret datorekkefølge',
            'end_date' => now()->addYear()->toDateString(),
            'binding_end_date' => now()->addYears(2)->toDateString(),
        ]);
        $contract->client()->update(['billing_email' => null]);
        $this->item($contract);

        $this->assertFalse($contract->fresh()->isReady());
        $this->actingAs($this->tech)
            ->post(route('tech.contracts.send-contract', $contract))
            ->assertSessionHas('error', 'Contract is not ready to be sent. Please check items and terms.');
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'approval_status' => 'draft',
            'customer_document_snapshot' => null,
        ]);

        $exception = null;

        try {
            app(CaptureContractCustomerDocument::class)->handle($contract, 'sent_contract');
        } catch (DomainException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(DomainException::class, $exception);
        $this->assertSame(
            'Kundedokumentet kan ikke fryses før kontrakten har tjenester, vilkår og en gyldig avtaleperiode.',
            $exception->getMessage(),
        );
        $this->assertNull($contract->fresh()->customer_document_snapshot);
    }

    #[Test]
    public function legal_identity_is_required_for_new_documents_but_complete_sent_snapshots_remain_approvable(): void
    {
        $companyProfile = app(CompanyProfileSettings::class);
        $readiness = app(ContractDocumentReadiness::class);

        $missingSupplier = $this->contract([
            'description' => 'Nytt dokument uten leverandøridentitet',
        ]);
        $missingSupplier->client()->update(['billing_email' => null]);
        $this->item($missingSupplier);
        $companyProfile->update([
            'legal_name' => null,
            'organization_number' => null,
        ]);
        $supplierMessage = $readiness->failureMessage($missingSupplier->fresh());

        $this->assertSame(
            'Kundedokumentet kan ikke sendes eller godkjennes før følgende er registrert: leverandørens juridiske navn, leverandørens organisasjonsnummer.',
            $supplierMessage,
        );
        $this->assertIdentityBlocksNewCustomerDocumentTransitions(
            $missingSupplier,
            $supplierMessage,
        );

        $companyProfile->update([
            'legal_name' => 'Trønder Data AS',
            'organization_number' => '999888777',
        ]);
        $missingCustomer = $this->contract([
            'description' => 'Nytt dokument uten kundeidentitet',
        ]);
        $missingCustomer->client()->update([
            'org_no' => null,
            'billing_email' => null,
        ]);
        $this->item($missingCustomer);
        $customerMessage = $readiness->failureMessage($missingCustomer->fresh());

        $this->assertSame(
            'Kundedokumentet kan ikke sendes eller godkjennes før følgende er registrert: kundens organisasjonsnummer.',
            $customerMessage,
        );
        $this->assertIdentityBlocksNewCustomerDocumentTransitions(
            $missingCustomer,
            $customerMessage,
        );

        $grandfathered = $this->contract([
            'description' => 'Komplett sendt dokument med senere identitetsmangel',
        ]);
        $grandfathered->client()->update(['billing_email' => null]);
        $this->item($grandfathered);
        $storedSnapshot = app(CaptureContractCustomerDocument::class)
            ->handle($grandfathered, 'sent_contract');
        $grandfathered->forceFill([
            'approval_status' => 'sent_contract',
            'sent_at' => now(),
        ])->saveQuietly();

        $this->assertSame(1, $storedSnapshot['schema_version']);
        $this->assertSame('Trønder Data AS', $storedSnapshot['parties']['supplier']['name']);
        $this->assertSame('999888777', $storedSnapshot['parties']['supplier']['organization_number']);
        $this->assertSame('987654321', $storedSnapshot['parties']['customer']['organization_number']);

        $companyProfile->update([
            'legal_name' => null,
            'organization_number' => null,
        ]);
        $grandfathered->client()->update(['org_no' => null]);
        $this->assertNotSame('', $readiness->failureMessage($grandfathered->fresh()));

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.approve-manual', $grandfathered))
            ->assertSessionHas('success', 'Contract manually approved and marked as Won.');
        $won = $grandfathered->fresh();

        $this->assertSame('won', $won->approval_status);
        $this->assertNotNull($won->accepted_at);
        $this->assertSame($storedSnapshot, $won->customer_document_snapshot);

        $resolved = app(ContractCustomerDocument::class)->resolve($won);
        $this->assertSame('Godkjent', $resolved['document']['status']);
        $this->assertSame('Trønder Data AS', $resolved['parties']['supplier']['name']);
        $this->assertSame('999888777', $resolved['parties']['supplier']['organization_number']);
        $this->assertSame('987654321', $resolved['parties']['customer']['organization_number']);
    }

    #[Test]
    public function approved_contract_with_a_captured_customer_document_is_projected_and_can_download_pdf(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 09:00:00'));
        $contract = $this->contract([
            'approval_status' => 'draft',
            'start_date' => now()->addMonth()->toDateString(),
        ]);
        $this->item($contract, null, [
            'name' => 'Historisk godkjent tjeneste',
            'unit_price' => '250.00',
        ]);
        $captured = app(CaptureContractCustomerDocument::class)->handle($contract, 'approved');
        $this->travelTo(Carbon::parse('2026-08-24 09:05:00'));
        $contract->forceFill([
            'approval_status' => 'approved',
            'accepted_at' => now(),
        ])->saveQuietly();
        $contract = $contract->fresh();

        $document = app(ContractCustomerDocument::class)->resolve($contract);

        $this->assertSame('Avtale', $document['document']['type']);
        $this->assertSame('Godkjent', $document['document']['status']);
        $this->assertTrue($document['approval']['accepted']);
        $this->assertSame('Godkjent avtale', $document['approval']['title']);
        $this->assertSame($captured, $contract->customer_document_snapshot);

        $resolvedAgain = app(CaptureContractCustomerDocument::class)->handle($contract, 'approved');
        $this->assertSame($captured, $resolvedAgain);
        $this->assertSame($captured, $contract->fresh()->customer_document_snapshot);

        $pdf = $this->actingAs($this->tech)
            ->get(route('tech.contracts.pdf', $contract))
            ->assertOk();

        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    #[Test]
    public function unchanged_legacy_sent_documents_without_snapshot_fail_closed(): void
    {
        foreach (['sent_quote', 'sent_contract'] as $offset => $status) {
            $baseTime = Carbon::parse('2026-08-25 06:00:00')->addDays($offset);
            $this->travelTo($baseTime);
            $contract = $this->contract([
                'description' => 'Uendret historisk sendt dokument '.$status,
            ]);
            $this->item($contract, null, [
                'name' => 'Historisk sendt tjeneste '.$status,
                'unit_price' => '250.00',
            ]);

            $this->travelTo($baseTime->copy()->addMinutes(5));
            $contract->forceFill([
                'approval_status' => $status,
                'sent_at' => now(),
                'customer_document_snapshot' => null,
            ])->saveQuietly();
            $legacy = $contract->fresh();

            $this->assertNull($legacy->customer_document_snapshot);
            $this->assertLegacyProjectionAndCaptureBlocked($legacy);
            $this->assertSame($status, $legacy->fresh()->approval_status);
            $this->assertNull($legacy->fresh()->customer_document_snapshot);
        }
    }

    #[Test]
    public function named_legacy_attestation_creates_audited_immutable_snapshot_once(): void
    {
        $legacy = $this->contract([
            'description' => 'Historisk avtale som krever navngitt attestasjon',
        ]);
        $item = $this->item($legacy, null, [
            'name' => 'Attestert historisk tjeneste',
            'unit_price' => '250.00',
        ]);
        $legacy->forceFill([
            'approval_status' => 'sent_contract',
            'sent_at' => now(),
            'secure_token' => str()->random(64),
            'customer_document_snapshot' => null,
        ])->saveQuietly();
        $legacy = $legacy->fresh();

        $review = $this->actingAs($this->tech)
            ->get(route('tech.contracts.show', $legacy))
            ->assertOk()
            ->assertSee('Historisk kundedokument er sperret')
            ->assertSee('Attester kontrollert rekonstruksjon')
            ->assertSee(route('tech.contracts.customer-document.attest-legacy', $legacy), false);
        $initialFingerprint = data_get(
            $review->viewData('validation'),
            'legacy_attestation_fingerprint',
        );

        $this->assertIsString($initialFingerprint);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $initialFingerprint);
        $this->assertSame(
            'Avtale',
            data_get($review->viewData('validation'), 'legacy_attestation_document_type'),
        );
        $review->assertSee('value="'.$initialFingerprint.'"', false);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.customer-document.attest-legacy', $legacy), [
                'attestation_note' => 'Kontrollert mot signert PDF datert 24.08.2026.',
                'legacy_attestation_fingerprint' => $initialFingerprint,
                'legacy_attestation_document_type' => 'Avtale',
            ])
            ->assertSessionHasErrors(['confirm_legacy_attestation']);
        $this->assertNull($legacy->fresh()->customer_document_snapshot);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.customer-document.attest-legacy', $legacy), [
                'attestation_note' => 'Kontrollert mot signert PDF datert 24.08.2026.',
                'confirm_legacy_attestation' => '1',
                'legacy_attestation_document_type' => 'Avtale',
            ])
            ->assertSessionHasErrors(['legacy_attestation_fingerprint']);
        $this->assertNull($legacy->fresh()->customer_document_snapshot);

        $submittedNote = '<strong>Kontrollert mot signert PDF</strong> datert 24.08.2026 og arkivref. A-123.';
        $plainNote = 'Kontrollert mot signert PDF datert 24.08.2026 og arkivref. A-123.';
        $item->forceFill(['unit_price' => '275.00'])->save();

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.customer-document.attest-legacy', $legacy), [
                'attestation_note' => $submittedNote,
                'confirm_legacy_attestation' => '1',
                'legacy_attestation_fingerprint' => $initialFingerprint,
                'legacy_attestation_document_type' => 'Avtale',
            ])
            ->assertSessionHas(
                'error',
                'Rekonstruksjonsgrunnlaget er endret siden det ble vist. Last siden på nytt, kontroller hele dokumentet igjen og attester deretter.',
            );
        $this->assertNull($legacy->fresh()->customer_document_snapshot);

        $reloadedReview = $this->actingAs($this->tech)
            ->get(route('tech.contracts.show', $legacy))
            ->assertOk();
        $currentFingerprint = data_get(
            $reloadedReview->viewData('validation'),
            'legacy_attestation_fingerprint',
        );

        $this->assertIsString($currentFingerprint);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $currentFingerprint);
        $this->assertNotSame($initialFingerprint, $currentFingerprint);
        $this->assertSame(
            'Avtale',
            data_get($reloadedReview->viewData('validation'), 'legacy_attestation_document_type'),
        );

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.customer-document.attest-legacy', $legacy), [
                'attestation_note' => $submittedNote,
                'confirm_legacy_attestation' => '1',
                'legacy_attestation_fingerprint' => $currentFingerprint,
                'legacy_attestation_document_type' => 'Avtale',
            ])
            ->assertSessionHas(
                'success',
                'Det historiske kundedokumentet er attestert og lagret som et uforanderlig snapshot.',
            );
        $attested = $legacy->fresh();
        $snapshot = $attested->customer_document_snapshot;
        $metadata = data_get(
            $attested->approval_metadata,
            'customer_document_legacy_attestation',
        );

        $this->assertIsArray($snapshot);
        $this->assertSame(1, $snapshot['schema_version']);
        $this->assertSame('manual_tech_reconstruction', $metadata['source']);
        $this->assertSame('sent_contract', $metadata['status_at_attestation']);
        $this->assertSame('Avtale', $metadata['original_document_type']);
        $this->assertSame($this->tech->id, $metadata['attested_by_user_id']);
        $this->assertSame($this->tech->name, $metadata['attested_by_name']);
        $this->assertSame($plainNote, $metadata['note']);
        $this->assertSame(
            hash('sha256', json_encode(
                $snapshot,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            )),
            $metadata['snapshot_sha256'],
        );
        $this->assertSame($snapshot, app(ContractCustomerDocument::class)->resolve($attested));
        $this->get(route('contracts.public.view', $attested->secure_token))->assertOk();

        $item->forceFill(['unit_price' => '999.00'])->save();
        app(CompanyProfileSettings::class)->update(['legal_name' => 'Endret Leverandør AS']);
        $this->assertSame($snapshot, app(ContractCustomerDocument::class)->resolve($attested->fresh()));
        $this->assertSame($snapshot, $attested->fresh()->customer_document_snapshot);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.customer-document.attest-legacy', $attested), [
                'attestation_note' => 'Et forsøk på å erstatte allerede attestert snapshot.',
                'confirm_legacy_attestation' => '1',
                'legacy_attestation_fingerprint' => $currentFingerprint,
                'legacy_attestation_document_type' => 'Avtale',
            ])
            ->assertSessionHas(
                'error',
                'Kundedokumentet har allerede et uforanderlig snapshot og kan ikke erstattes.',
            );
        $this->assertSame($snapshot, $attested->fresh()->customer_document_snapshot);

        $draft = $this->contract(['description' => 'Utkast kan ikke legacy-attesteres']);
        $this->item($draft);
        $this->actingAs($this->tech)
            ->post(route('tech.contracts.customer-document.attest-legacy', $draft), [
                'attestation_note' => 'Kontrollert notat som er langt nok for valideringen.',
                'confirm_legacy_attestation' => '1',
                'legacy_attestation_fingerprint' => str_repeat('0', 64),
                'legacy_attestation_document_type' => 'Avtale',
            ])
            ->assertSessionHas(
                'error',
                'Bare historiske sendte eller godkjente kontrakter kan attesteres.',
            );
        $this->assertNull($draft->fresh()->customer_document_snapshot);
    }

    #[Test]
    public function legacy_won_attestation_requires_an_explicit_original_document_type_and_binds_its_fingerprint(): void
    {
        $legacy = $this->contract([
            'description' => 'Historisk godkjent dokument med tvetydig originaltype',
        ]);
        $this->item($legacy, null, ['unit_price' => '325.00']);
        $legacy->forceFill([
            'approval_status' => 'won',
            'accepted_at' => now(),
            'accepted_by_name' => 'Historisk kunde',
            'customer_document_snapshot' => null,
        ])->saveQuietly();
        $legacy = $legacy->fresh();

        $ambiguousReview = $this->actingAs($this->tech)
            ->get(route('tech.contracts.show', $legacy))
            ->assertOk()
            ->assertSee('Kontroller som tilbud')
            ->assertSee('Kontroller som avtale');
        $ambiguousValidation = $ambiguousReview->viewData('validation');

        $this->assertTrue($ambiguousValidation['legacy_attestation_document_type_ambiguous']);
        $this->assertFalse($ambiguousValidation['legacy_attestation_preview_available']);
        $this->assertNull($ambiguousValidation['legacy_attestation_document_type']);
        $this->assertNull($ambiguousValidation['legacy_attestation_fingerprint']);
        $ambiguousReview
            ->assertSee('Historisk kundedokument – dokumenttype må velges')
            ->assertSee('Ingen rekonstruksjon vises før originalunderlaget har fastslått dokumenttypen.')
            ->assertDontSee(route('tech.contracts.customer-document.attest-legacy', $legacy), false)
            ->assertDontSee('325,00 kr');

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.customer-document.attest-legacy', $legacy), [
                'attestation_note' => 'Kontrollert originaldokument uten valgt type.',
                'confirm_legacy_attestation' => '1',
                'legacy_attestation_fingerprint' => str_repeat('0', 64),
            ])
            ->assertSessionHasErrors(['legacy_attestation_document_type']);
        $this->assertNull($legacy->fresh()->customer_document_snapshot);

        $offerReview = $this->actingAs($this->tech)
            ->get(route('tech.contracts.show', [
                'contract' => $legacy,
                'legacy_document_type' => 'Tilbud',
            ]))
            ->assertOk();
        $agreementReview = $this->actingAs($this->tech)
            ->get(route('tech.contracts.show', [
                'contract' => $legacy,
                'legacy_document_type' => 'Avtale',
            ]))
            ->assertOk();
        $offerFingerprint = data_get(
            $offerReview->viewData('validation'),
            'legacy_attestation_fingerprint',
        );
        $agreementFingerprint = data_get(
            $agreementReview->viewData('validation'),
            'legacy_attestation_fingerprint',
        );

        $this->assertIsString($offerFingerprint);
        $this->assertIsString($agreementFingerprint);
        $this->assertNotSame($offerFingerprint, $agreementFingerprint);
        $this->assertSame('Tilbud', data_get($offerReview->viewData('customerDocument'), 'document.type'));
        $this->assertSame('Avtale', data_get($agreementReview->viewData('customerDocument'), 'document.type'));

        $note = 'Kontrollert mot historisk tilbud i signert kundearkiv.';
        $this->actingAs($this->tech)
            ->post(route('tech.contracts.customer-document.attest-legacy', $legacy), [
                'attestation_note' => $note,
                'confirm_legacy_attestation' => '1',
                'legacy_attestation_fingerprint' => $offerFingerprint,
                'legacy_attestation_document_type' => 'Avtale',
            ])
            ->assertSessionHas(
                'error',
                'Rekonstruksjonsgrunnlaget er endret siden det ble vist. Last siden på nytt, kontroller hele dokumentet igjen og attester deretter.',
            );
        $this->assertNull($legacy->fresh()->customer_document_snapshot);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.customer-document.attest-legacy', $legacy), [
                'attestation_note' => $note,
                'confirm_legacy_attestation' => '1',
                'legacy_attestation_fingerprint' => $offerFingerprint,
                'legacy_attestation_document_type' => 'Tilbud',
            ])
            ->assertSessionHas(
                'success',
                'Det historiske kundedokumentet er attestert og lagret som et uforanderlig snapshot.',
            );
        $attested = $legacy->fresh();

        $this->assertSame('Tilbud', data_get($attested->customer_document_snapshot, 'document.type'));
        $this->assertSame(
            'Tilbud',
            data_get(
                $attested->approval_metadata,
                'customer_document_legacy_attestation.original_document_type',
            ),
        );
    }

    #[Test]
    public function legacy_sent_documents_without_snapshot_remain_blocked_with_additional_mutable_evidence(): void
    {
        foreach (['missing-sent-at', 'item', 'rate', 'cloudfactory-marker'] as $offset => $evidence) {
            $baseTime = Carbon::parse('2026-08-28 06:00:00')->addDays($offset);
            $this->travelTo($baseTime);
            $contract = $this->contract([
                'description' => 'Historisk sendt dokument med risikospor '.$evidence,
            ]);
            $item = $this->item($contract, null, [
                'name' => 'Historisk sendt tjeneste '.$evidence,
                'unit_price' => '250.00',
                'source' => $evidence === 'cloudfactory-marker' ? 'cloudfactory' : 'nexum',
            ]);
            if ($evidence === 'rate') {
                $this->rate($item, 'Historisk sendt sats', '900.00', 'NOK');
            }

            $this->travelTo($baseTime->copy()->addMinutes(5));
            $sentAt = now();
            $contract->forceFill([
                'approval_status' => 'sent_contract',
                'sent_at' => $evidence === 'missing-sent-at' ? null : $sentAt,
                'customer_document_snapshot' => null,
            ])->saveQuietly();

            $this->travelTo($baseTime->copy()->addMinutes(10));
            if ($evidence === 'item') {
                $item->forceFill(['unit_price' => '275.00'])->save();
                $this->assertTrue($item->fresh()->updated_at->gt($sentAt));
            } elseif ($evidence === 'rate') {
                $rate = $item->timeRates()->firstOrFail();
                $rate->forceFill(['amount_ex_vat' => '950.00'])->save();
                $this->assertTrue($rate->fresh()->updated_at->gt($sentAt));
            } elseif ($evidence === 'cloudfactory-marker') {
                $this->assertSame('cloudfactory', $item->fresh()->source);
                $this->assertFalse($item->fresh()->updated_at->gt($sentAt));
            } else {
                $this->assertNull($contract->fresh()->sent_at);
            }

            $this->assertLegacyProjectionAndCaptureBlocked($contract->fresh());
        }
    }

    #[Test]
    public function legacy_accepted_contract_without_snapshot_rejects_post_acceptance_item_or_rate_changes(): void
    {
        foreach (['item', 'rate'] as $offset => $changedRecord) {
            $baseTime = Carbon::parse('2026-08-25 08:00:00')->addDays($offset);
            $this->travelTo($baseTime);
            $contract = $this->contract([
                'description' => 'Legacy-kontrakt med endret '.$changedRecord,
            ]);
            $item = $this->item($contract, null, [
                'name' => 'Historisk tjeneste '.$changedRecord,
                'unit_price' => '250.00',
            ]);
            if ($changedRecord === 'rate') {
                $this->rate($item, 'Historisk sats', '900.00', 'NOK');
            }

            $this->travelTo($baseTime->copy()->addMinutes(5));
            $acceptedAt = now();
            $contract->forceFill([
                'approval_status' => 'won',
                'accepted_at' => $acceptedAt,
            ])->saveQuietly();

            $this->travelTo($baseTime->copy()->addMinutes(10));
            if ($changedRecord === 'item') {
                $item->forceFill(['unit_price' => '275.00'])->save();
                $this->assertTrue($item->fresh()->updated_at->gt($acceptedAt));
            } else {
                $rate = $item->timeRates()->firstOrFail();
                $rate->forceFill(['amount_ex_vat' => '950.00'])->save();
                $this->assertTrue($rate->fresh()->updated_at->gt($acceptedAt));
            }

            $this->assertLegacyProjectionAndCaptureBlocked($contract->fresh());
        }
    }

    #[Test]
    public function legacy_accepted_contract_without_snapshot_rejects_cloudfactory_markers_and_history(): void
    {
        $integration = Integration::query()->create([
            'name' => 'Cloud Factory legacy guard fixture',
            'type' => 'cloudfactory',
            'status' => 'active',
            'config' => [],
            'is_healthy' => true,
        ]);

        foreach (['item-marker', 'subscription-history', 'amendment-history'] as $offset => $evidence) {
            $baseTime = Carbon::parse('2026-08-27 08:00:00')->addDays($offset);
            $this->travelTo($baseTime);
            $contract = $this->contract([
                'description' => 'Legacy-kontrakt med CloudFactory-spor '.$evidence,
            ]);
            $item = $this->item($contract, null, [
                'name' => 'Historisk CloudFactory-tjeneste '.$evidence,
                'source' => $evidence === 'item-marker' ? 'cloudfactory' : 'nexum',
            ]);

            $this->travelTo($baseTime->copy()->addMinutes(5));
            $contract->forceFill([
                'approval_status' => 'won',
                'accepted_at' => now(),
            ])->saveQuietly();

            if ($evidence === 'subscription-history') {
                $contract->cloudFactorySubscriptions()->create([
                    'integration_id' => $integration->id,
                    'client_id' => $contract->client_id,
                    'provider_family' => 'microsoft_nce',
                    'external_subscription_id' => 'legacy-subscription-'.$contract->id,
                    'name' => 'Historisk abonnement',
                    'quantity' => 1,
                    'status' => 'active',
                ]);
                $this->assertTrue($contract->cloudFactorySubscriptions()->exists());
            } elseif ($evidence === 'amendment-history') {
                $subscription = Subscription::query()->create([
                    'integration_id' => $integration->id,
                    'client_id' => $contract->client_id,
                    'contract_id' => null,
                    'provider_family' => 'microsoft_nce',
                    'external_subscription_id' => 'legacy-amendment-source-'.$contract->id,
                    'name' => 'Historisk endringskilde',
                    'quantity' => 1,
                    'status' => 'active',
                ]);
                $contract->cloudFactoryAmendments()->create([
                    'subscription_id' => $subscription->id,
                    'contract_item_id' => $item->id,
                    'change_type' => 'quantity',
                    'old_quantity' => 1,
                    'new_quantity' => 2,
                    'effective_at' => now(),
                    'origin' => 'cloudfactory',
                ]);
                $this->assertFalse($contract->cloudFactorySubscriptions()->exists());
                $this->assertTrue($contract->cloudFactoryAmendments()->exists());
            } else {
                $this->assertSame('cloudfactory', $item->fresh()->source);
                $this->assertFalse($item->fresh()->updated_at->gt($contract->fresh()->accepted_at));
            }

            $this->assertLegacyProjectionAndCaptureBlocked($contract->fresh());
        }
    }

    #[Test]
    public function capture_dates_unversioned_appendices_at_capture_time_not_original_draft_creation(): void
    {
        $this->travelTo(Carbon::parse('2026-08-24 13:45:00'));

        $contract = $this->contract([
            'description' => 'Et eldre utkast som sendes i dag.',
            'terms_snapshot' => 'Avtalevilkår uten egen versjonsdato.',
        ]);
        $this->item($contract);
        $contract->forceFill([
            'created_at' => Carbon::parse('2024-01-15 09:00:00'),
        ])->saveQuietly();

        $snapshot = app(CaptureContractCustomerDocument::class)->handle($contract, 'sent_contract');

        $this->assertSame('24.08.2026 13:45', $snapshot['document']['generated_at']);
        $this->assertSame('24.08.2026', $snapshot['document']['generated_date']);
        $this->assertSame('24.09.2026', $snapshot['dates']['start']['value']);
        $this->assertSame('24.08.2027', $snapshot['dates']['end']['value']);
        $this->assertSame('24.07.2027', $snapshot['dates']['binding_end']['value']);
        $this->assertSame('24.08.2026', $snapshot['appendices'][0]['date']);
        $this->assertNotSame('15.01.2024', $snapshot['appendices'][0]['date']);

        $contract->forceFill([
            'approval_status' => 'won',
            'accepted_at' => Carbon::parse('2026-08-25 09:07:00'),
            'accepted_by_name' => 'Norsk Datokunde',
        ])->saveQuietly();
        $accepted = app(ContractCustomerDocument::class)->resolve($contract->fresh());

        $this->assertSame('25.08.2026 09:07', $accepted['approval']['date']);
        $this->assertSame(
            'Godkjent av Norsk Datokunde 25.08.2026 09:07',
            $accepted['approval']['text'],
        );
    }

    #[Test]
    public function stored_v1_snapshot_requires_exact_canonical_column_order_keys_and_labels(): void
    {
        $canonical = [
            'service' => 'Tjeneste',
            'short_description' => 'Kort beskrivelse',
            'scope' => 'Omfang',
            'unit_price' => 'Enhetspris',
            'billing' => 'Fakturering',
            'total' => 'Sum',
        ];
        $mutations = [
            'shuffled columns' => function (array $snapshot): array {
                $columns = $snapshot['columns'];
                $snapshot['columns'] = [
                    'short_description' => $columns['short_description'],
                    'service' => $columns['service'],
                    'scope' => $columns['scope'],
                    'unit_price' => $columns['unit_price'],
                    'billing' => $columns['billing'],
                    'total' => $columns['total'],
                ];

                return $snapshot;
            },
            'English label' => function (array $snapshot): array {
                $snapshot['columns']['service'] = 'Service';

                return $snapshot;
            },
        ];

        foreach ($mutations as $case => $mutate) {
            $contract = $this->contract(['description' => 'Column schema '.$case]);
            $this->item($contract);
            $snapshot = app(ContractCustomerDocument::class)->build($contract);

            $this->assertSame($canonical, $snapshot['columns']);
            $this->assertStoredSnapshotRejected($contract, $mutate($snapshot), $case);
        }
    }

    #[Test]
    public function stored_v1_snapshot_rejects_unknown_internal_keys_and_non_list_collections(): void
    {
        $contract = $this->contract([
            'description' => 'Strict closed customer schema',
            'sla_snapshot' => 'Supportinnhold for lukket schema.',
            'secure_token' => str()->random(64),
        ]);
        $item = $this->item($contract);
        $this->rate($item, 'Lukket schemasats', '950.00', 'NOK');
        $snapshot = app(ContractCustomerDocument::class)->build($contract);
        $mutations = [
            'top-level internal metadata' => function (array $document): array {
                $document['internal_metadata'] = ['source' => 'private'];

                return $document;
            },
            'document internal identifier' => function (array $document): array {
                $document['document']['internal_id'] = 123;

                return $document;
            },
            'date internal value' => function (array $document): array {
                $document['dates']['start']['internal_timezone'] = 'UTC';

                return $document;
            },
            'party internal value' => function (array $document): array {
                $document['parties']['customer']['internal_client_id'] = 456;

                return $document;
            },
            'approval internal value' => function (array $document): array {
                $document['approval']['internal_user_id'] = 789;

                return $document;
            },
            'line sku and cost leak' => function (array $document): array {
                $document['lines'][0]['sku'] = 'PRIVATE-SKU';
                $document['lines'][0]['internal_cost'] = '12.34';

                return $document;
            },
            'billing internal value' => function (array $document): array {
                $document['lines'][0]['billing']['internal_multiplier'] = 12;

                return $document;
            },
            'money internal value' => function (array $document): array {
                $document['totals']['monthly']['internal_currency'] = 'NOK';

                return $document;
            },
            'rate internal value' => function (array $document): array {
                $document['rates']['items'][0]['internal_rate_id'] = 321;

                return $document;
            },
            'support internal value' => function (array $document): array {
                $document['support']['internal_sla_id'] = 654;

                return $document;
            },
            'appendix internal value' => function (array $document): array {
                $document['appendices'][0]['internal_term_id'] = 987;

                return $document;
            },
            'associative lines collection' => function (array $document): array {
                $document['lines'] = ['first' => $document['lines'][0]];

                return $document;
            },
            'associative rates collection' => function (array $document): array {
                $document['rates']['items'] = ['first' => $document['rates']['items'][0]];

                return $document;
            },
            'associative appendices collection' => function (array $document): array {
                $document['appendices'] = ['first' => $document['appendices'][0]];

                return $document;
            },
        ];

        foreach ($mutations as $case => $mutate) {
            $invalid = $mutate($snapshot);
            $this->assertStoredSnapshotRejected($contract, $invalid, $case);

            $this->get(route('contracts.public.view', $contract->secure_token))
                ->assertStatus(409);
            $this->assertSame($invalid, $contract->fresh()->customer_document_snapshot);
        }

        $this->assertNull($contract->fresh()->viewed_at);
    }

    #[Test]
    public function stored_v1_snapshot_rejects_scalar_type_coercion_for_version_boole_and_money(): void
    {
        $contract = $this->contract([
            'description' => 'Strict typed customer schema',
            'secure_token' => str()->random(64),
        ]);
        $item = $this->item($contract, null, ['setup_fee' => '25.00']);
        $this->rate($item, 'Typed schemasats', '950.00', 'NOK');
        $snapshot = app(ContractCustomerDocument::class)->build($contract);
        $mutations = [
            'schema version numeric string' => function (array $document): array {
                $document['schema_version'] = '1';

                return $document;
            },
            'approval boolean string' => function (array $document): array {
                $document['approval']['accepted'] = 'false';

                return $document;
            },
            'total minor numeric string' => function (array $document): array {
                $document['totals']['monthly']['minor'] = (string) $document['totals']['monthly']['minor'];

                return $document;
            },
            'unit price minor numeric string' => function (array $document): array {
                $document['lines'][0]['unit_price']['minor'] = (string) $document['lines'][0]['unit_price']['minor'];

                return $document;
            },
            'setup fee minor numeric string' => function (array $document): array {
                $document['lines'][0]['billing']['setup_fee']['minor'] = (string) $document['lines'][0]['billing']['setup_fee']['minor'];

                return $document;
            },
            'line total minor numeric string' => function (array $document): array {
                $document['lines'][0]['total']['minor'] = (string) $document['lines'][0]['total']['minor'];

                return $document;
            },
            'rate amount minor numeric string' => function (array $document): array {
                $document['rates']['items'][0]['amount']['minor'] = (string) $document['rates']['items'][0]['amount']['minor'];

                return $document;
            },
            'included boolean string' => function (array $document): array {
                $document['lines'][0]['total']['included'] = 'false';

                return $document;
            },
        ];

        foreach ($mutations as $case => $mutate) {
            $invalid = $mutate($snapshot);
            $this->assertStoredSnapshotRejected($contract, $invalid, $case);

            $this->get(route('contracts.public.view', $contract->secure_token))
                ->assertStatus(409);
            $this->assertSame($invalid, $contract->fresh()->customer_document_snapshot);
        }

        $this->assertNull($contract->fresh()->viewed_at);
    }

    #[Test]
    public function stored_v1_snapshot_totals_equal_each_line_cadence_plus_all_setup_fees(): void
    {
        $contract = $this->contract(['description' => 'Exact cadence aggregate schema']);
        foreach ([
            ['cadence' => 'monthly', 'price' => '100.00', 'setup' => '10.00'],
            ['cadence' => 'quarterly', 'price' => '200.00', 'setup' => '20.00'],
            ['cadence' => 'yearly', 'price' => '300.00', 'setup' => '30.00'],
            ['cadence' => 'one_time', 'price' => '400.00', 'setup' => '40.00'],
        ] as $line) {
            $this->item($contract, null, [
                'name' => ucfirst($line['cadence']).' aggregate line',
                'sku' => 'AGGREGATE-'.strtoupper($line['cadence']),
                'unit_price' => $line['price'],
                'billing_interval' => $line['cadence'],
                'setup_fee' => $line['setup'],
            ]);
        }

        $snapshot = app(ContractCustomerDocument::class)->build($contract);
        $expected = [
            'monthly' => 10000,
            'quarterly' => 20000,
            'yearly' => 30000,
            'one_time' => 50000,
        ];
        $expectedBillingLabels = [
            'monthly' => 'Månedlig',
            'quarterly' => 'Kvartalsvis',
            'yearly' => 'Årlig',
            'one_time' => 'Engangsbeløp',
        ];

        $this->assertSame(
            $expected,
            collect($snapshot['totals'])
                ->map(fn (array $amount): int => $amount['minor'])
                ->all(),
        );
        $this->assertSame(
            $expectedBillingLabels,
            collect($snapshot['lines'])
                ->mapWithKeys(fn (array $line): array => [
                    $line['billing']['cadence'] => $line['billing']['label'],
                ])
                ->all(),
        );

        foreach ($expected as $cadence => $minor) {
            $mutated = $snapshot;
            $mutated['totals'][$cadence] = $this->snapshotNokAmount($minor + 1);
            $this->assertStoredSnapshotRejected(
                $contract,
                $mutated,
                $cadence.' canonical total disagrees with line and setup aggregate',
            );
        }

        foreach (array_keys($expectedBillingLabels) as $index => $cadence) {
            $mutated = $snapshot;
            $mutated['lines'][$index]['billing']['label'] = 'Noncanonical '.$cadence;
            $this->assertStoredSnapshotRejected(
                $contract,
                $mutated,
                $cadence.' billing label is not canonical Norwegian',
            );
        }
    }

    #[Test]
    public function stored_v1_snapshot_requires_semantically_consistent_money_and_included_values(): void
    {
        $mutations = [
            'total minor disagrees with decimal' => function (array $snapshot): array {
                $snapshot['totals']['monthly']['minor']++;

                return $snapshot;
            },
            'line unit price display is not canonical' => function (array $snapshot): array {
                $snapshot['lines'][0]['unit_price']['display'] = '100.00 NOK';

                return $snapshot;
            },
            'line total decimal disagrees with minor' => function (array $snapshot): array {
                $snapshot['lines'][0]['total']['decimal'] = '999.00';

                return $snapshot;
            },
            'rate amount decimal disagrees with minor' => function (array $snapshot): array {
                $snapshot['rates']['items'][0]['amount']['decimal'] = '999.00';

                return $snapshot;
            },
            'billing label is not canonical Norwegian' => function (array $snapshot): array {
                $snapshot['lines'][0]['billing']['label'] = 'Monthly';

                return $snapshot;
            },
            'date label is not canonical Norwegian' => function (array $snapshot): array {
                $snapshot['dates']['start']['label'] = 'Start date';

                return $snapshot;
            },
            'party label is not canonical Norwegian' => function (array $snapshot): array {
                $snapshot['parties']['customer']['label'] = 'Customer';

                return $snapshot;
            },
            'rates title is not canonical Norwegian' => function (array $snapshot): array {
                $snapshot['rates']['title'] = 'Additional rates';

                return $snapshot;
            },
            'support title is not canonical Norwegian' => function (array $snapshot): array {
                $snapshot['support']['title'] = 'Support and response time';

                return $snapshot;
            },
            'rate unit label is not canonical Norwegian' => function (array $snapshot): array {
                $snapshot['rates']['items'][0]['unit_label'] = 'hour';
                $snapshot['rates']['items'][0]['display'] = $snapshot['rates']['items'][0]['amount']['display'].' / hour';

                return $snapshot;
            },
            'rate currency is not canonical uppercase' => function (array $snapshot): array {
                $snapshot['rates']['items'][0]['currency'] = 'nok';

                return $snapshot;
            },
            'duplicate full rate identity is retained' => function (array $snapshot): array {
                $snapshot['rates']['items'][] = $snapshot['rates']['items'][0];

                return $snapshot;
            },
            'appendix numbering is not sequential' => function (array $snapshot): array {
                $snapshot['appendices'][0]['number'] = 2;

                return $snapshot;
            },
            'stored appendix leaks internal Unversioned label' => function (array $snapshot): array {
                $snapshot['appendices'][0]['version'] = 'Unversioned';

                return $snapshot;
            },
            'nonzero line is marked included' => function (array $snapshot): array {
                $snapshot['lines'][0]['total']['included'] = true;
                $snapshot['lines'][0]['total']['display'] = 'Inkludert';

                return $snapshot;
            },
            'zero line is not marked included' => function (array $snapshot): array {
                $snapshot['lines'][0]['total'] = [
                    'minor' => 0,
                    'decimal' => '0.00',
                    'display' => '0,00 kr',
                    'included' => false,
                ];
                $snapshot['totals']['monthly'] = [
                    'minor' => 0,
                    'decimal' => '0.00',
                    'display' => '0,00 kr',
                ];

                return $snapshot;
            },
        ];

        foreach ($mutations as $case => $mutate) {
            $contract = $this->contract([
                'description' => 'Money schema '.$case,
                'sla_snapshot' => 'Support på hverdager med avtalt responstid.',
            ]);
            $item = $this->item($contract, null, ['unit_price' => '100.00']);
            $this->rate($item, 'Semantisk sats', '950.00', 'NOK');
            $snapshot = app(ContractCustomerDocument::class)->build($contract);

            $this->assertStoredSnapshotRejected($contract, $mutate($snapshot), $case);
        }
    }

    #[Test]
    public function unknown_snapshot_schema_fails_closed_for_sent_and_won_contracts_without_rebuilding(): void
    {
        foreach (['sent_contract', 'won'] as $status) {
            $contract = $this->contract([
                'approval_status' => $status,
                'description' => 'Levende kontraktstekst som aldri skal rebuildes',
            ]);
            $item = $this->item($contract, null, [
                'name' => 'Levende kontraktslinje',
                'unit_price' => '999.00',
            ]);
            $unknownSnapshot = [
                'schema_version' => 999,
                'immutable_marker' => 'ukjent-skjema-'.$status,
            ];
            $contract->forceFill([
                'customer_document_snapshot' => $unknownSnapshot,
            ])->saveQuietly();
            $item->forceFill(['name' => 'Endret levende linje'])->save();
            $exception = null;

            try {
                app(ContractCustomerDocument::class)->resolve($contract->fresh());
            } catch (UnexpectedValueException $caught) {
                $exception = $caught;
            }

            $this->assertInstanceOf(UnexpectedValueException::class, $exception);
            $this->assertSame(
                'Unsupported customer document snapshot schema; refusing to rebuild an immutable document.',
                $exception->getMessage(),
            );
            $this->assertSame($unknownSnapshot, $contract->fresh()->customer_document_snapshot);
        }
    }

    #[Test]
    public function empty_scalar_and_partial_v1_stored_snapshots_fail_closed_without_live_rebuild(): void
    {
        $projector = app(ContractCustomerDocument::class);
        $capture = app(CaptureContractCustomerDocument::class);

        foreach ([
            'empty-array' => ['status' => 'sent_contract', 'snapshot' => []],
            'scalar' => ['status' => 'won', 'snapshot' => 'legacy-corrupt-snapshot'],
            'partial-v1' => ['status' => 'sent_contract', 'snapshot' => ['schema_version' => 1]],
        ] as $case => $fixture) {
            $contract = $this->contract([
                'approval_status' => $fixture['status'],
                'description' => 'Levende tekst som aldri skal brukes '.$case,
            ]);
            $this->item($contract, null, [
                'name' => 'Levende linje som aldri skal rebuildes '.$case,
                'unit_price' => '999.00',
            ]);
            DB::table('contracts')->where('id', $contract->id)->update([
                'customer_document_snapshot' => json_encode(
                    $fixture['snapshot'],
                    JSON_THROW_ON_ERROR,
                ),
            ]);
            $stored = $contract->fresh();

            $this->assertTrue($projector->hasStoredSnapshot($stored));

            foreach ([
                'resolve' => fn (): array => $projector->resolve($stored),
                'capture' => fn (): array => $capture->handle($stored, 'won'),
            ] as $operation => $callback) {
                $exception = null;

                try {
                    $callback();
                } catch (UnexpectedValueException $caught) {
                    $exception = $caught;
                }

                $this->assertInstanceOf(
                    UnexpectedValueException::class,
                    $exception,
                    $case.' '.$operation.' must fail closed.',
                );
                $this->assertSame(
                    'Unsupported customer document snapshot schema; refusing to rebuild an immutable document.',
                    $exception->getMessage(),
                );
                $this->assertSame(
                    $fixture['snapshot'],
                    json_decode(DB::table('contracts')->where('id', $contract->id)->value('customer_document_snapshot'), true, flags: JSON_THROW_ON_ERROR),
                );
            }
        }
    }

    #[Test]
    public function replacing_a_won_customer_document_snapshot_is_rejected_and_preserves_it(): void
    {
        $contract = $this->contract(['description' => 'Opprinnelig tilbud']);
        $this->item($contract);
        $capture = app(CaptureContractCustomerDocument::class);
        $snapshot = $capture->handle($contract, 'sent_quote');
        $contract->forceFill([
            'approval_status' => 'won',
            'accepted_at' => now(),
            'accepted_by_name' => 'Kunde Kontakt',
        ])->saveQuietly();

        $exception = null;

        try {
            $capture->replace($contract->fresh(), 'won');
        } catch (LogicException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(LogicException::class, $exception);
        $this->assertSame(
            'Customer document snapshots can only be replaced while the contract is editable.',
            $exception->getMessage(),
        );
        $this->assertSame($snapshot, $contract->fresh()->customer_document_snapshot);
    }

    #[Test]
    public function contract_customer_document_migration_refuses_to_drop_existing_snapshots(): void
    {
        $contract = $this->contract(['description' => 'Snapshot som må overleve rollback']);
        $snapshot = [
            'schema_version' => ContractCustomerDocument::SCHEMA_VERSION,
            'immutable_marker' => 'must-survive-schema-rollback',
        ];
        $contract->forceFill([
            'customer_document_snapshot' => $snapshot,
        ])->saveQuietly();
        $migration = require database_path(
            'migrations/2026_08_24_160000_add_contract_customer_document_snapshot_fields.php',
        );
        $exception = null;

        try {
            $migration->down();
        } catch (LogicException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(LogicException::class, $exception);
        $this->assertSame(
            'Refusing to drop immutable snapshots, customer wording/units, or explicit visible-rate classifications. Export, verify, and deliberately clear every affected field before rollback.',
            $exception->getMessage(),
        );
        $this->assertTrue(Schema::hasColumn('contracts', 'customer_document_snapshot'));
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
        ]);
        $this->assertSame(
            $snapshot,
            $contract->fresh()->customer_document_snapshot,
        );
    }

    #[Test]
    public function contract_customer_document_migration_refuses_to_drop_customer_wording_units_or_visible_rates(): void
    {
        $migration = require database_path(
            'migrations/2026_08_24_160000_add_contract_customer_document_snapshot_fields.php',
        );
        $message = 'Refusing to drop immutable snapshots, customer wording/units, or explicit visible-rate classifications. Export, verify, and deliberately clear every affected field before rollback.';
        $assertRefused = function (string $evidence) use ($migration, $message): void {
            $exception = null;

            try {
                $migration->down();
            } catch (LogicException $caught) {
                $exception = $caught;
            }

            $this->assertInstanceOf(LogicException::class, $exception, $evidence);
            $this->assertSame($message, $exception->getMessage(), $evidence);
            $this->assertTrue(Schema::hasColumn('contracts', 'customer_document_snapshot'), $evidence);
        };

        $contract = $this->contract();
        $item = $this->item($contract, null, [
            'customer_description' => 'Eksplisitt kundetekst',
            'customer_unit_singular' => null,
            'customer_unit_plural' => null,
        ]);
        $assertRefused('customer wording');
        $item->forceFill(['customer_description' => null])->saveQuietly();

        $service = $this->service();
        $assertRefused('customer units');
        $service->forceFill([
            'customer_unit_singular' => null,
            'customer_unit_plural' => null,
        ])->saveQuietly();

        $this->rate($item, 'Eksplisitt synlig sats', '950.00', 'NOK');
        $assertRefused('visible rate classification');
    }

    #[Test]
    public function customer_description_backfill_is_chunked_null_only_and_idempotent(): void
    {
        $service = $this->service();
        $service->forceFill([
            'short_description' => '<p>Første <strong>kundetekst</strong>.</p>',
        ])->save();
        $backfilledContract = $this->contract(['description' => 'Null description backfill']);
        $backfilledItem = $this->item($backfilledContract, $service, [
            'customer_description' => null,
        ]);
        $preservedContract = $this->contract(['description' => 'Explicit description preservation']);
        $preservedItem = $this->item($preservedContract, $service, [
            'customer_description' => 'Eksplisitt kontraktstekst.',
        ]);
        $migrationPath = database_path(
            'migrations/2026_08_24_160000_add_contract_customer_document_snapshot_fields.php',
        );
        $migration = require $migrationPath;

        $migration->up();

        $this->assertSame('Første kundetekst.', $backfilledItem->fresh()->customer_description);
        $this->assertSame('Eksplisitt kontraktstekst.', $preservedItem->fresh()->customer_description);

        $service->forceFill([
            'short_description' => '<p>Senere katalogtekst som ikke skal overskrive.</p>',
        ])->save();
        $migration->up();

        $this->assertSame('Første kundetekst.', $backfilledItem->fresh()->customer_description);
        $this->assertSame('Eksplisitt kontraktstekst.', $preservedItem->fresh()->customer_description);

        $source = file_get_contents($migrationPath);
        $this->assertIsString($source);
        $matched = preg_match(
            '/private function backfillEditableContractItemDescriptions\(\): void\s*\{(?<body>.*?)\n    \}\n\n    private function markPlatformDefaultRatesAsCustomerVisible/s',
            $source,
            $matches,
        );

        $this->assertSame(1, $matched);
        $this->assertStringContainsString('->chunkById(', $matches['body']);
        $this->assertStringContainsString(
            "->whereNull('contract_items.customer_description')",
            $matches['body'],
        );
        $this->assertStringContainsString(
            "->whereNull('customer_description')",
            $matches['body'],
        );
    }

    #[Test]
    public function long_multiline_customer_descriptions_survive_the_actual_pdf_render(): void
    {
        $contractDescription = "Første avsnitt med æøå og kundetilpasset innhold.\n\n"
            .collect(range(1, 24))
                ->map(fn (int $number): string => "Avsnitt {$number}: Dette er en lang kundebeskrivelse som må beholde linjeskift i hele PDF-løpet.")
                ->implode("\n\n")
            ."\n\nSiste PDF-kontrollpunkt for avtalen.";
        $lineDescription = "Tjenestelinjen starter her.\n"
            .str_repeat("Detaljert leveransepunkt som skal vises på egen linje.\n", 6)
            .'Siste PDF-kontrollpunkt for tjenesten.';
        $contract = $this->contract(['description' => $contractDescription]);
        $this->item($contract, null, [
            'name' => 'Omfattende leveranse',
            'customer_description' => $lineDescription,
        ]);
        $document = app(ContractCustomerDocument::class)->resolve($contract->fresh());

        $this->assertSame($contractDescription, $document['description']);
        $this->assertSame($lineDescription, $document['lines'][0]['short_description']);

        $pdf = $this->actingAs($this->tech)
            ->get(route('tech.contracts.pdf', $contract))
            ->assertOk();
        $content = (string) $pdf->getContent();

        $this->assertSame('application/pdf', $pdf->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $content);
        $this->assertPdfContainsText($content, 'Første avsnitt med æøå');
        $this->assertPdfContainsText($content, 'Siste PDF-kontrollpunkt for avtalen.');
        $this->assertPdfContainsText($content, 'Tjenestelinjen starter her.');
        $this->assertPdfContainsText($content, 'tjenesten.');
    }

    #[Test]
    public function accepted_sent_quote_keeps_offer_identity_in_resolve_api_and_pdf_footer(): void
    {
        $contract = $this->contract(['description' => 'Tilbud som aksepteres']);
        $this->item($contract, null, [
            'name' => 'Akseptert tilbudslinje',
            'unit_price' => '750.00',
        ]);
        $captured = app(CaptureContractCustomerDocument::class)->handle($contract, 'sent_quote');
        $contract->forceFill([
            'approval_status' => 'won',
            'sent_at' => now()->subHour(),
            'accepted_at' => now(),
            'accepted_by_name' => 'Kunde Kontakt',
        ])->saveQuietly();

        $resolved = app(ContractCustomerDocument::class)->resolve($contract->fresh());

        $this->assertSame('Tilbud', $captured['document']['type']);
        $this->assertSame('Tilbud', $resolved['document']['type']);
        $this->assertSame('Godkjent', $resolved['document']['status']);
        $this->assertTrue($resolved['approval']['accepted']);
        $this->assertSame('Akseptert tilbud', $resolved['approval']['title']);
        $this->assertStringStartsWith('Akseptert av Kunde Kontakt', $resolved['approval']['text']);

        Sanctum::actingAs($this->tech, ['commercial.read']);
        $this->getJson(route('api.v1.commercial.contracts.show', $contract))
            ->assertOk()
            ->assertJsonPath('data.customer_document.document.type', 'Tilbud')
            ->assertJsonPath('data.customer_document.document.status', 'Godkjent')
            ->assertJsonPath('data.customer_document.approval.title', 'Akseptert tilbud');

        $pdf = $this->actingAs($this->tech)
            ->get(route('tech.contracts.pdf', $contract))
            ->assertOk();
        $content = (string) $pdf->getContent();

        $this->assertPdfContainsText($content, 'Tilbud #'.$contract->id);
        $this->assertPdfContainsText($content, 'Akseptert tilbud');
        $this->assertFalse($this->pdfContainsText($content, 'Avtale #'.$contract->id));
    }

    private function assertPdfContainsText(string $pdf, string $expected): void
    {
        $this->assertTrue(
            $this->pdfContainsText($pdf, $expected),
            "The rendered PDF did not contain the expected text [{$expected}].",
        );
    }

    private function pdfContainsText(string $pdf, string $expected): bool
    {
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches) === false) {
            return false;
        }

        $needles = [
            $expected,
            mb_convert_encoding($expected, 'UTF-16BE', 'UTF-8'),
        ];

        foreach ($matches[1] as $stream) {
            $decoded = @gzuncompress($stream);
            $haystack = $decoded === false ? $stream : $decoded;

            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function validatedMetadataRequest(Contracts $contract, string $description): ContractsRequest
    {
        $request = ContractsRequest::create('/', 'PUT', [
            'client_id' => $contract->client_id,
            'sla_id' => $contract->sla_id,
            'created_by' => $contract->created_by,
            'description' => $description,
            'start_date' => $contract->start_date->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'binding_end_date' => $contract->binding_end_date?->toDateString(),
            'auto_renew' => $contract->auto_renew,
            'renewal_months' => $contract->renewal_months,
            'allow_indexing_during_binding' => $contract->allow_indexing_during_binding,
            'allow_decrease_during_binding' => $contract->allow_decrease_during_binding,
            'allow_license_additions' => $contract->allow_license_additions,
            'allow_license_increases' => $contract->allow_license_increases,
            'allow_license_decreases' => $contract->allow_license_decreases,
            'allow_license_price_updates' => $contract->allow_license_price_updates,
            'max_index_pct_binding' => $contract->max_index_pct_binding,
            'post_binding_index_pct' => $contract->post_binding_index_pct,
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->setUserResolver(fn (): User => $this->tech);
        $request->validateResolved();

        return $request;
    }

    private function assertIdentityBlocksNewCustomerDocumentTransitions(
        Contracts $contract,
        string $message,
    ): void {
        $this->actingAs($this->tech)
            ->post(route('tech.contracts.send-contract', $contract))
            ->assertSessionHas('error', $message);
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'approval_status' => 'draft',
            'customer_document_snapshot' => null,
        ]);

        $exception = null;

        try {
            app(CaptureContractCustomerDocument::class)->handle($contract, 'sent_contract');
        } catch (DomainException $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(DomainException::class, $exception);
        $this->assertSame($message, $exception->getMessage());
        $this->assertNull($contract->fresh()->customer_document_snapshot);

        $this->actingAs($this->tech)
            ->post(route('tech.contracts.approve-manual', $contract))
            ->assertSessionHas('error', $message);
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'approval_status' => 'draft',
            'customer_document_snapshot' => null,
        ]);
    }

    private function contract(array $overrides = []): Contracts
    {
        $client = Client::factory()->create([
            'name' => 'Sluttkontroll Kunde AS',
            'org_no' => '987654321',
        ]);

        $contract = Contracts::query()->create(array_replace([
            'client_id' => $client->id,
            'description' => 'Sluttkontrollavtale',
            'start_date' => now()->addMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'binding_end_date' => now()->addMonths(11)->toDateString(),
            'auto_renew' => true,
            'renewal_months' => 12,
            'approval_status' => 'draft',
            'terms_snapshot' => 'Generelle avtalevilkår.',
            'created_by' => $this->tech->id,
        ], $overrides));

        app(ContractTermSnapshotReadiness::class)->markReviewed($contract, $this->tech->id);

        return $contract->fresh();
    }

    private function service(): Services
    {
        $unit = Units::query()->create([
            'name' => 'Enhet',
            'short' => 'stk',
        ]);

        return Services::query()->create([
            'sku' => 'FINAL-REVIEW-'.str()->random(8),
            'name' => 'Sluttkontrolltjeneste',
            'unitId' => $unit->id,
            'status' => 'published',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'monthly',
            'price_ex_vat' => '100.00',
            'price_including_tax' => '125.00',
            'customer_unit_singular' => 'enhet',
            'customer_unit_plural' => 'enheter',
            'created_by_user_id' => $this->tech->id,
            'updated_by_user_id' => $this->tech->id,
        ]);
    }

    private function item(Contracts $contract, ?Services $service = null, array $overrides = []): ContractItem
    {
        return ContractItem::query()->create(array_replace([
            'contract_id' => $contract->id,
            'service_id' => $service?->id,
            'name' => $service?->name ?? 'Sluttkontrolltjeneste',
            'sku' => $service?->sku ?? 'FINAL-REVIEW',
            'customer_description' => 'Kundevendt tjenestebeskrivelse.',
            'unit_price' => '100.00',
            'quantity' => 1,
            'unit' => 'enhet',
            'customer_unit_singular' => 'enhet',
            'customer_unit_plural' => 'enheter',
            'billing_interval' => 'monthly',
            'discount_value' => null,
            'discount_type' => null,
            'setup_fee' => '0.00',
            'uses_contract_default_sla' => true,
        ], $overrides));
    }

    private function assertLegacyProjectionAndCaptureBlocked(Contracts $contract): void
    {
        $legacyReadiness = app(ContractLegacyDocumentReadiness::class);
        $message = $legacyReadiness->failureMessage();

        foreach ([
            'resolve' => fn (): array => app(ContractCustomerDocument::class)->resolve($contract),
            'capture' => fn (): array => app(CaptureContractCustomerDocument::class)->handle($contract),
        ] as $operation => $callback) {
            $exception = null;

            try {
                $callback();
            } catch (DomainException $caught) {
                $exception = $caught;
            }

            $this->assertInstanceOf(
                DomainException::class,
                $exception,
                $operation.' must require manual legacy reconstruction.',
            );
            $this->assertSame($message, $exception->getMessage());
            $this->assertNull($contract->fresh()->customer_document_snapshot);
        }
    }

    private function assertStoredSnapshotRejected(
        Contracts $contract,
        array $snapshot,
        string $case,
    ): void {
        $contract->forceFill([
            'approval_status' => 'sent_contract',
            'sent_at' => now(),
            'customer_document_snapshot' => $snapshot,
        ])->saveQuietly();
        $stored = $contract->fresh();

        foreach ([
            'resolve' => fn (): array => app(ContractCustomerDocument::class)->resolve($stored),
            'capture' => fn (): array => app(CaptureContractCustomerDocument::class)->handle($stored, 'sent_contract'),
        ] as $operation => $callback) {
            $exception = null;

            try {
                $callback();
            } catch (UnexpectedValueException $caught) {
                $exception = $caught;
            }

            $this->assertInstanceOf(
                UnexpectedValueException::class,
                $exception,
                $case.' '.$operation.' must fail closed.',
            );
            $this->assertSame(
                'Unsupported customer document snapshot schema; refusing to rebuild an immutable document.',
                $exception->getMessage(),
            );
            $this->assertSame($snapshot, $contract->fresh()->customer_document_snapshot);
        }
    }

    /** @return array{minor: int, decimal: string, display: string} */
    private function snapshotNokAmount(int $minor): array
    {
        $pricing = app(ContractPricing::class);

        return [
            'minor' => $minor,
            'decimal' => $pricing->decimalFromMinor($minor),
            'display' => $pricing->formatMinor($minor),
        ];
    }

    private function rate(
        ContractItem $item,
        string $name,
        string $amount,
        string $currency,
    ): void {
        $item->timeRates()->create([
            'name' => $name,
            'code' => 'FINAL-RATE-'.$currency,
            'rate_type' => 'labor',
            'unit' => 'hour',
            'amount_ex_vat' => $amount,
            'currency' => $currency,
            'is_active' => true,
            'is_customer_visible' => true,
            'sort_order' => 10,
        ]);
    }
}
