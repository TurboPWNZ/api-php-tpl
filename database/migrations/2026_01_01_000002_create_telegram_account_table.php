<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTelegramAccountTable extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('telegram_account', function (Blueprint $table) {
            $table->id();
            $table->string('username', 255)->unique();
            $table->decimal('balance', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('telegram_account');
    }
}
