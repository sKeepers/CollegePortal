<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Одноразовые коды входа, которые бот присылает в мессенджер.
 *
 * Таблица, а не кэш: код входа — это событие безопасности, и на вопрос «кто и
 * когда запрашивал вход по коду» надо уметь ответить после перезапуска.
 * Сам код здесь не лежит — только его отпечаток, как и пароль.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 32);
            $table->string('code_hash');
            $table->timestamp('expires_at');
            // Попытки считаются у кода, а не у человека: иначе подбирающий,
            // запрашивая новый код, обнулял бы себе счётчик.
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->string('request_ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_codes');
    }
};
