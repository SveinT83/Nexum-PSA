<?php

namespace App\Modules\Signal\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Signal\Actions\ExecuteSignalAction;
use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Signal\Support\SignalRuleDefinition;
use App\Modules\Storage\Actions\QueueSupplierOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierOrderImportActionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function rule_builder_stores_supplier_import_options(): void
    {
        Permission::findOrCreate('signal.view', 'web');
        Permission::findOrCreate('signal.rule.manage', 'web');

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo(['signal.view', 'signal.rule.manage']);

        $this->actingAs($user)
            ->get(route('tech.admin.system.signals.rules.create'))
            ->assertOk()
            ->assertSee('Import supplier purchase order');

        $this->actingAs($user)
            ->post(route('tech.admin.system.signals.rules.store'), [
                'name' => 'Import supplier orders',
                'is_active' => 1,
                'priority' => 10,
                'conditions' => [
                    'match' => 'all',
                    'groups' => [[
                        'match' => 'all',
                        'conditions' => [[
                            'field' => 'signal_type',
                            'operator' => 'equals',
                            'value' => 'supplier_order_email',
                        ]],
                    ]],
                ],
                'actions' => [[
                    'type' => 'storage_supplier_order_import',
                    'profile_id' => '42',
                    'queue' => '0',
                ]],
            ])
            ->assertRedirect();

        $action = SignalRule::query()
            ->where('name', 'Import supplier orders')
            ->firstOrFail()
            ->actions[0];

        $this->assertSame('storage_supplier_order_import', $action['type']);
        $this->assertSame(42, $action['profile_id']);
        $this->assertFalse($action['queue']);
    }

    #[Test]
    public function supplier_import_action_options_are_validated(): void
    {
        $definition = app(SignalRuleDefinition::class);

        foreach ([
            [['type' => 'storage_supplier_order_import', 'profile_id' => 0]],
            [['type' => 'storage_supplier_order_import', 'queue' => 1]],
        ] as $actions) {
            try {
                $definition->decodeAndValidate('{}', json_encode($actions, JSON_THROW_ON_ERROR));
                $this->fail('Invalid supplier import action was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('actions_json', $exception->errors());
            }
        }
    }

    #[Test]
    public function executor_hands_off_to_storage_with_stable_idempotency_and_queue_default(): void
    {
        $signal = Signal::query()->create([
            'source_domain' => 'email',
            'signal_type' => 'supplier_order_email',
            'occurred_at' => now(),
        ]);
        $rule = SignalRule::query()->create([
            'name' => 'Storage handoff',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [],
            'actions' => [['type' => 'storage_supplier_order_import']],
        ]);

        $handoff = new class
        {
            public array $calls = [];

            public function handle(
                Signal $signal,
                SignalRule $rule,
                array $action,
                string $idempotencyKey,
            ): array {
                $this->calls[] = compact('signal', 'rule', 'action', 'idempotencyKey');

                return [
                    'type' => 'storage_supplier_order_import',
                    'status' => $action['queue'] ? 'queued' : 'done',
                    'idempotency_key' => $idempotencyKey,
                ];
            }
        };

        app()->instance(
            \App\Modules\Storage\Actions\QueueSupplierOrderImport::class,
            $handoff,
        );

        $result = app(ExecuteSignalAction::class)->handle(
            $signal,
            $rule,
            ['type' => 'storage_supplier_order_import', 'profile_id' => 7],
            2,
        );

        $expectedKey = "signal:{$signal->id}:rule:{$rule->id}:action:2";

        $this->assertSame('queued', $result['status']);
        $this->assertSame($expectedKey, $result['idempotency_key']);
        $this->assertCount(1, $handoff->calls);
        $this->assertTrue($handoff->calls[0]['action']['queue']);
        $this->assertSame(7, $handoff->calls[0]['action']['profile_id']);
        $this->assertSame($expectedKey, $handoff->calls[0]['idempotencyKey']);
    }

    #[Test]
    public function storage_handoff_rederives_authentication_from_the_email_instead_of_signal_payload(): void
    {
        $account = EmailAccount::query()->create([
            'address' => 'purchasing@example.invalid',
            'from_name' => 'Purchasing',
            'is_active' => true,
            'imap_host' => 'imap.example.invalid',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'purchasing@example.invalid',
            'imap_secret' => 'test-secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.invalid',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'purchasing@example.invalid',
            'smtp_secret' => 'test-secret',
            'smtp_auth_type' => 'password',
        ]);
        $message = EmailMessage::query()->create([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => '9901',
            'message_id' => '<supplier-order-9901@example.invalid>',
            'subject' => 'Order confirmation',
            'from_name' => 'Supplier',
            'from_email' => 'orders@supplier.example',
            'to_json' => [['name' => 'Purchasing', 'email' => 'purchasing@example.invalid']],
            'cc_json' => [],
            'headers_json' => [
                'Authentication-Results' => 'attacker.invalid; dkim=pass header.d=supplier.example',
            ],
            'received_at' => now(),
            'state' => 'untriaged',
            'body_text' => 'Synthetic supplier order confirmation.',
        ]);
        $signal = Signal::query()->create([
            'source_domain' => 'email',
            'source_type' => $message->getMorphClass(),
            'source_id' => (string) $message->id,
            'signal_type' => 'supplier_order_email',
            'payload' => [
                'email_message_id' => $message->id,
                'trusted_auth' => [
                    'authentication_passed' => true,
                    'authenticated_supplier_domain' => 'supplier.example',
                    'aligned' => true,
                ],
            ],
            'occurred_at' => now(),
        ]);
        $rule = SignalRule::query()->create([
            'name' => 'Supplier Storage handoff',
            'is_active' => true,
            'priority' => 10,
            'conditions' => [],
            'actions' => [['type' => 'storage_supplier_order_import']],
        ]);

        $result = app(QueueSupplierOrderImport::class)->handle(
            $signal,
            $rule,
            ['queue' => false],
            'signal-auth-rederive',
        );
        $import = PurchaseOrderImport::query()->findOrFail($result['import_id']);

        $this->assertSame('recorded', $result['status']);
        $this->assertTrue((bool) data_get($signal->payload, 'trusted_auth.authentication_passed'));
        $this->assertFalse((bool) data_get($import->trusted_auth_snapshot, 'authentication_passed'));
        $this->assertFalse((bool) data_get($import->safe_source_snapshot, 'trusted_auth.authentication_passed'));
    }
}
