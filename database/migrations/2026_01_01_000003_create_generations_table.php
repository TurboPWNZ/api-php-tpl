<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateGenerationsTable extends Migration
{
    public function up(): void
    {
        Capsule::schema()->create('generations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id'); // telegram_id, как в payments.user_id
            $table->string('status', 20)->default('processing')->index(); // processing|ready|failed
            $table->text('prompt')->nullable();
            $table->decimal('cost', 12, 2);
            $table->string('source_path', 255); // относительный путь под /storage
            $table->string('result_path', 255)->nullable();
            $table->string('comfy_prompt_id', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Capsule::schema()->dropIfExists('generations');
    }
}
