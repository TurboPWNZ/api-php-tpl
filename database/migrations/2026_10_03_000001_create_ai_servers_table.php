<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAiServersTable extends Migration
{
    public function up(): void
    {
        // Пул ИИ-серверов (ComfyUI). Один сервер — одна генерация за раз:
        // free → busy (generation_id) → free. offline — недоступен или
        // завис; воркер очереди периодически проверяет и возвращает в free.
        Capsule::schema()->create('ai_servers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->string('url', 255); // http://ip:port ComfyUI
            $table->string('status', 20)->default('free')->index(); // free|busy|offline
            $table->boolean('enabled')->default(true); // false — новых заказов не получает
            $table->unsignedBigInteger('generation_id')->nullable(); // что сейчас считает
            $table->unsignedInteger('fail_count')->default(0); // подряд неудачных обращений
            $table->text('last_error')->nullable();
            $table->timestamp('last_seen_at')->nullable(); // последний успешный ответ
            $table->timestamp('checked_at')->nullable();   // последняя проверка offline-сервера
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('ai_servers');
    }
}
