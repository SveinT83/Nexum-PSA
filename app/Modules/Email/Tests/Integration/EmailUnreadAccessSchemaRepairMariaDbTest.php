<?php

namespace App\Modules\Email\Tests\Integration;

use App\Modules\Email\Services\EmailUnreadSchemaState;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailUnreadAccessSchemaRepairMariaDbTest extends TestCase
{
    #[Test]
    public function native_mariadb_repairs_the_partial_contract_and_preserves_state(): void
    {
        $socket = (string) getenv('TDPSA_EMAIL_UNREAD_REPAIR_SOCKET');
        if ($socket === '') {
            $this->markTestSkipped('Set TDPSA_EMAIL_UNREAD_REPAIR_SOCKET to an isolated MariaDB socket.');
        }
        $this->assertStringStartsWith('/tmp/tdpsa-', $socket);
        $server = new PDO('mysql:unix_socket='.$socket, 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database = 'email_unread_repair_'.bin2hex(random_bytes(8));
        $server->exec('CREATE DATABASE `'.$database.'`');
        $original = config('database.default');
        config(['database.connections.unread_repair' => [
            'driver' => 'mariadb', 'database' => $database, 'unix_socket' => $socket,
            'host' => 'localhost', 'username' => 'root', 'password' => '',
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
        ], 'database.default' => 'unread_repair']);
        try {
            $this->createRecordedPartialFixture();
            $this->assertSame(EmailUnreadSchemaState::MODE_UNAVAILABLE, (new EmailUnreadSchemaState)->mode());
            $before = DB::table('email_message_user_states')->get()->toJson();
            $migration = require database_path('migrations/2026_09_17_110000_repair_email_unread_access_schema.php');
            $migration->up();
            $this->assertSame(EmailUnreadSchemaState::MODE_EPOCHS, (new EmailUnreadSchemaState)->mode());
            $this->assertSame($before, DB::table('email_message_user_states')->get()->toJson());
            $this->assertSame(3, DB::table('email_account_user_read_baselines')->count());
            $this->assertSame(1, (int) DB::table('email_account_user_read_baselines')
                ->where('email_account_id', 1)->where('user_id', 1)->value('ordinary_view_entitled'));
            $this->assertSame(0, (int) DB::table('email_account_user_read_baselines')
                ->where('email_account_id', 1)->where('user_id', 2)->value('ordinary_view_entitled'));
            $baseline = DB::table('email_account_user_read_baselines')->orderBy('id')->get()->toJson();
            $migration->up();
            $migration->down();
            $this->assertSame($baseline, DB::table('email_account_user_read_baselines')->orderBy('id')->get()->toJson());
            $this->assertFalse(Schema::hasIndex('email_message_user_states', 'email_message_user_states_unique'));
            $this->assertCount(3, Schema::getForeignKeys('email_account_user_read_baselines'));
            // Historical per-user states can now coexist in separate access epochs.
            DB::table('email_message_user_states')->insert([
                'email_message_id' => 1, 'user_id' => 1, 'access_epoch' => 2,
                'is_unread' => false, 'opened_count' => 0,
            ]);
            $this->assertSame(2, DB::table('email_message_user_states')->count());
        } finally {
            DB::disconnect('unread_repair');
            config(['database.default' => $original]);
            DB::purge('unread_repair');
            // Only the randomly named database created by this test may be removed.
            $this->assertMatchesRegularExpression('/^email_unread_repair_[a-f0-9]{16}$/', $database);
            $server->exec('DROP DATABASE `'.$database.'`');
        }
    }

    private function createRecordedPartialFixture(): void
    {
        Schema::create('migrations', function (Blueprint $table): void {
            $table->string('migration');
            $table->integer('batch');
        });
        DB::table('migrations')->insert([
            'migration' => '2026_08_16_104000_add_email_unread_access_baselines', 'batch' => 1,
        ]);
        Schema::create('user_management', fn (Blueprint $table) => $table->id());
        DB::table('user_management')->insert([['id' => 1], ['id' => 2]]);
        Schema::create('email_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('account_kind');
            $table->unsignedBigInteger('owner_id')->nullable();
        });
        DB::table('email_accounts')->insert([
            ['id' => 1, 'account_kind' => 'personal', 'owner_id' => 1],
            ['id' => 2, 'account_kind' => 'shared', 'owner_id' => null],
        ]);
        Schema::create('email_account_user_grants', function (Blueprint $table): void {
            $table->unsignedBigInteger('email_account_id');
            $table->unsignedBigInteger('user_id');
            $table->boolean('can_view');
        });
        DB::table('email_account_user_grants')->insert([
            ['email_account_id' => 1, 'user_id' => 2, 'can_view' => true],
            ['email_account_id' => 2, 'user_id' => 2, 'can_view' => true],
        ]);
        Schema::create('email_message_user_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_message_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('access_epoch')->default(1);
            $table->unsignedBigInteger('last_opened_placement_id')->nullable();
            $table->boolean('is_unread');
            $table->unsignedInteger('opened_count');
            foreach (['first_opened_at', 'last_opened_at', 'marked_read_at', 'marked_unread_at'] as $column) {
                $table->dateTime($column)->nullable();
            }
            $table->timestamps();
            $table->unique(['email_message_id', 'user_id'], 'email_message_user_states_unique');
        });
        DB::table('email_message_user_states')->insert([
            'email_message_id' => 1, 'user_id' => 1, 'access_epoch' => 1,
            'is_unread' => false, 'opened_count' => 4,
        ]);
    }
}
