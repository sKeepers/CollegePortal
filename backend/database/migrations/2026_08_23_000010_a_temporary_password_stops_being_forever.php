<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Срок годности у выданного пароля.
 *
 * Временный пароль задуман до первого входа, и отметка `must_change_password`
 * это обеспечивает. Но у записи, которой никто не пользовался, «первый вход» не
 * наступает никогда — и временный пароль живёт годами. Учётные записи при этом
 * заводятся пачкой на весь контингент, а портал смотрит наружу.
 *
 * Колонка пустая означает «срока нет»: свой пароль, заведённый человеком,
 * не устаревает. Заполняется она только там, где портал выдаёт пароль сам.
 *
 * Уже выданные пароли срока не получают: задним числом отключить вход
 * пятистам людям — это не починка, а поломка. Их меняет отдельная выдача.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'password_expires_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('password_expires_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'password_expires_at')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_expires_at');
        });
    }
};
