<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сетка звонков: какому номеру пары какое время соответствует.
 *
 * До этого времени в портале не было вовсе — начало и конец набирались руками у
 * каждой строки расписания. При шестидесяти пяти группах это тысячи ручных
 * вводов и ровно тот случай, где опечатка не видна: занятие встанет, просто на
 * десять минут не туда.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_times', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('lesson_number')->unique();
            $table->time('starts_at');
            $table->time('ends_at');
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_times');
    }
};
