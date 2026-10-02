<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateTemplatesTable extends Migration
{
    public function up(): void
    {
        // Шаблоны генерации: свой workflow ComfyUI, промпты, цены и превью
        // "до/после". Всё — в БД (и превью тоже): БД общая для докера и прода,
        // а storage/ у каждого свой.
        Capsule::schema()->create('templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            $table->integer('sort')->default(0); // меньше — выше в списке
            $table->boolean('enabled')->default(true);
            $table->decimal('cost', 12, 2);             // без описания
            $table->decimal('cost_with_prompt', 12, 2); // с описанием пользователя
            $table->text('prompt');                     // системный промпт
            $table->text('prompt_guest')->nullable();   // для гостей (не пополнявших), null — как prompt
            $table->longText('workflow');               // JSON ComfyUI с плейсхолдером {{user_promt}}
            $table->binary('preview_before')->nullable();
            $table->string('preview_before_mime', 32)->nullable();
            $table->binary('preview_after')->nullable();
            $table->string('preview_after_mime', 32)->nullable();
            $table->timestamps();
        });

        // binary() в MySQL — BLOB, это 64 КБ; превью до ~1 МБ.
        Capsule::connection()->statement('ALTER TABLE templates MODIFY preview_before MEDIUMBLOB NULL, MODIFY preview_after MEDIUMBLOB NULL');

        Capsule::schema()->table('generations', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable()->after('env');
        });
    }

    public function down(): void
    {
        Capsule::schema()->table('generations', function (Blueprint $table) {
            $table->dropColumn('template_id');
        });
        Capsule::schema()->dropIfExists('templates');
    }
}
