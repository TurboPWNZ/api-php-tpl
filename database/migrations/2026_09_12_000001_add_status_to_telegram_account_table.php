<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddStatusToTelegramAccountTable extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('telegram_account', function (Blueprint $table) {
            // 'guest' — ни разу не пополнял баланс, ему в генерации подставляется
            // systemPromtGuest; 'customer' — хотя бы одно пополнение прошло
            // успешно (см. Provider::updateAccountBalance), дальше всегда systemPromt.
            $table->string('status', 20)->default('guest')->index()->after('balance');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('telegram_account', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
}
