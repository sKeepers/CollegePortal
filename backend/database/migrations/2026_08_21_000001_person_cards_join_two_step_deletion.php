<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Карточку человека можно пометить на удаление — как карточки студента,
 * преподавателя и сотрудника.
 *
 * До сих пор человек не удалялся никак: двухшаговое удаление знало только
 * профильные карточки, поэтому удалённый студент исчезал, а человек оставался
 * в разделе «Люди» навсегда.
 *
 * Прямое удаление строки не годилось и не годится: внешние ключи у студента,
 * преподавателя, выпускника и заявления обнуляющие, и человек ушёл бы молча,
 * оставив карточки без ФИО — ровно та поломка, из-за которой на боевом портале
 * были «?» вместо имени. Поэтому удаление мягкое, а зависимые записи снимаются
 * явным каскадом, состав которого записывается в заявку: без него возврат из
 * корзины восстановил бы человека, но не то, что ушло вместе с ним.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('deletion_requests', function (Blueprint $table) {
            $table->json('cascade')->nullable()->after('subject_label');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('deletion_requests', function (Blueprint $table) {
            $table->dropColumn('cascade');
        });
    }
};
