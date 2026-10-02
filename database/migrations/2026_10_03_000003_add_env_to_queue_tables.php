<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddEnvToQueueTables extends Migration
{
    public function up(): void
    {
        // БД общая для локального докера и прода, а файлы фото у каждого
        // свои. Воркер берёт только серверы и заказы своего окружения
        // (config queue.env), иначе прод-воркер схватит локальный заказ
        // (и не найдёт его фото) или отдаст прод-заказ локальному ComfyUI.
        Capsule::schema()->table('ai_servers', function (Blueprint $table) {
            $table->string('env', 20)->default('prod')->after('id')->index();
        });
        Capsule::schema()->table('generations', function (Blueprint $table) {
            $table->string('env', 20)->default('prod')->after('user_id');
            $table->index(['env', 'status']);
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('generations', function (Blueprint $table) {
            $table->dropIndex(['env', 'status']);
            $table->dropColumn('env');
        });
        Capsule::schema()->table('ai_servers', function (Blueprint $table) {
            $table->dropColumn('env');
        });
    }
}
