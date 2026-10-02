<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddQueueFieldsToGenerationsTable extends Migration
{
    public function up(): void
    {
        // Генерация теперь сначала встаёт в очередь (status=queued), воркер
        // отдаёт её свободному серверу из ai_servers (status=processing).
        Capsule::schema()->table('generations', function (Blueprint $table) {
            $table->unsignedBigInteger('server_id')->nullable()->after('cost');
            // Итоговый промпт для workflow (системный [+ пользовательский]) —
            // считается при заказе: гость/клиент определяется на этот момент.
            $table->text('full_prompt')->nullable()->after('prompt');
            $table->unsignedTinyInteger('attempts')->default(0)->after('comfy_prompt_id');
            $table->timestamp('started_at')->nullable()->after('attempts');
            $table->timestamp('finished_at')->nullable()->after('started_at');
        });

        // Генерации, запущенные старым механизмом (сразу в comfyui.domain),
        // не привязаны ни к одному серверу пула — воркер их не подберёт.
        // Перезапускаем через очередь: кредиты уже списаны, результат будет.
        Capsule::table('generations')
            ->where('status', 'processing')
            ->whereNull('server_id')
            ->update(['status' => 'queued', 'comfy_prompt_id' => null]);
    }

    public function down(): void
    {
        Capsule::schema()->table('generations', function (Blueprint $table) {
            $table->dropColumn(['server_id', 'full_prompt', 'attempts', 'started_at', 'finished_at']);
        });
    }
}
