<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $userTable = env('AUTH_USER_TABLE', 'user_management');

        Schema::create('user_profiles', function (Blueprint $table) use ($userTable) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('avatar_path')->nullable();
            $table->string('work_phone', 50)->nullable();
            $table->string('private_phone', 50)->nullable();
            $table->string('timezone', 80)->default(config('app.timezone', 'UTC'));
            $table->json('working_hours')->nullable();
            $table->text('availability_notes')->nullable();
            $table->text('profile_notes')->nullable();
            $table->unsignedBigInteger('migrated_from_ticket_technician_profile_id')->nullable();
            $table->timestamp('migrated_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')
                ->references('id')
                ->on($userTable)
                ->cascadeOnDelete();
        });

        $this->backfillProfiles($userTable);
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }

    private function backfillProfiles(string $userTable): void
    {
        if (! Schema::hasTable($userTable)) {
            return;
        }

        $hasWorkPhone = Schema::hasColumn($userTable, 'phone_work');
        $hasPrivatePhone = Schema::hasColumn($userTable, 'phone_private');
        $hasTicketProfiles = Schema::hasTable('ticket_technician_profiles');

        DB::table($userTable)
            ->orderBy('id')
            ->chunkById(100, function ($users) use ($hasWorkPhone, $hasPrivatePhone, $hasTicketProfiles) {
                foreach ($users as $user) {
                    $ticketProfile = $hasTicketProfiles
                        ? DB::table('ticket_technician_profiles')->where('user_id', $user->id)->first()
                        : null;

                    DB::table('user_profiles')->updateOrInsert(
                        ['user_id' => $user->id],
                        [
                            'work_phone' => $hasWorkPhone ? $user->phone_work : null,
                            'private_phone' => $hasPrivatePhone ? $user->phone_private : null,
                            'timezone' => $ticketProfile?->timezone ?? config('app.timezone', 'UTC'),
                            'working_hours' => $ticketProfile?->working_hours,
                            'profile_notes' => $ticketProfile?->notes,
                            'migrated_from_ticket_technician_profile_id' => $ticketProfile?->id,
                            'migrated_at' => now(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }
            });
    }
};
