<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddDeletedAtToGenerationsTable extends Migration
{
    public function up(): void
    {
        Capsule::schema()->table('generations', function (Blueprint $table) {
            // Пользователь удаляет генерацию из "Мои генерации" — запись
            // остаётся (история списаний), файлы с диска удаляются.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('generations', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
}
