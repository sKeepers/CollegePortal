<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Экраны прав, ролей, учётных записей и журнала не выдают отказ за пустоту.
 *
 * Замер 04.09.2026 17:20 UTC на стенде, обрыв одного запроса на экран: все
 * четыре при отказе говорили «Пользователи не найдены», «Роли не найдены»,
 * «Разрешения не найдены», «Событий аудита нет» — то есть **утверждали о правах
 * человека и о следе в журнале** ровно то, чего не спрашивали. Баннер ошибки
 * рядом стоял и не спасал: читают крупную подпись, а не мелкую полосу.
 *
 * Здесь это дороже, чем на других экранах. «Разрешения не найдены» читается как
 * «прав у роли нет», а «Событий аудита нет» — как «выгрузка следа не оставила»:
 * пункт 29 приёмки именно на этот экран и смотрит, и по такому ответу человек
 * пойдёт искать поломку в журнале сервера вместо обновления страницы.
 *
 * Проверка читает исходник, потому что поведение здесь не выдумать из ответов
 * API: подпись рисуется на стороне браузера, а фронтенд-тестов в портале нет
 * вовсе. Обратная сторона — обход с обрывом запроса (`asking-failed-access.mjs`
 * рядом с `look.js`); держать надо обе, они ловят разное.
 *
 * **Проверка держится на паре «подпись пустоты → рядом спрошен отказ», а не на
 * едином тексте отказа.** Заголовки у экранов могут быть свои («Реестр не
 * получен», «Отчёт не получен») — это не мешает; сюда дописывается строка на
 * экран, и соседняя область может добавить свои тем же способом.
 */
class EmptyStateTellsFailureTest extends TestCase
{
    /** @var array<string, string> экран → подпись, которая врала при отказе */
    private const SCREENS = [
        'pages/admin/users/UsersPage.vue' => 'Пользователи не найдены',
        'pages/admin/roles/RolesPage.vue' => 'Роли не найдены',
        'pages/admin/permissions/PermissionsPage.vue' => 'Разрешения не найдены',
        'pages/admin/audit/AuditPage.vue' => 'Событий аудита нет',
    ];

    public function test_the_empty_list_asks_whether_the_request_failed(): void
    {
        $mute = [];
        $missing = [];
        $read = 0;

        foreach (self::SCREENS as $path => $lie) {
            $source = $this->frontendFile($path);

            if ($source === null) {
                $missing[] = $path;
                continue;
            }

            $read++;
            $states = $this->emptyStates($source);
            $forTheList = array_values(array_filter($states, fn (string $tag): bool => str_contains($tag, $lie)));

            if ($forTheList === []) {
                $mute[] = $path.': не нашлось пустого состояния списка (подпись «'.$lie.'» переписали — проверьте, что вместе с ней не потеряли отличие отказа)';
                continue;
            }

            foreach ($forTheList as $tag) {
                if (! str_contains($tag, 'store.error')) {
                    $mute[] = $path.': «'.$lie.'» говорится, не спросив, не отказал ли запрос';
                }
            }
        }

        // Ни одного файла — фронтенда рядом нет, это прогон по `backend/`.
        // **Часть файлов — другое дело:** экран переименовали или унесли, и молчать
        // тут нельзя. Строка списка прикрывала бы уже несуществующее, а проверка
        // выглядела бы работающей — ровно так список внешних ключей у `groups`
        // разошёлся со схемой, оставаясь зелёным.
        if ($read === 0) {
            $this->markTestSkipped('Рядом нет каталога frontend: проверка идёт в полном дереве, как в CI.');
        }

        $this->assertSame([], $missing,
            "экран из списка не найден — переименован или унесён, впишите новый путь:\n".implode("\n", $missing));

        $this->assertSame([], $mute, "пустое состояние выдаёт отказ за пустоту:\n".implode("\n", $mute));
    }

    /**
     * Теги `AppEmptyState` целиком, вместе с их свойствами.
     *
     * @return list<string>
     */
    private function emptyStates(string $source): array
    {
        preg_match_all('~<AppEmptyState\b[^>]*/?>~su', $source, $tags);

        return $tags[0];
    }

    private function frontendFile(string $path): ?string
    {
        $full = base_path('../frontend/src/'.$path);

        return is_readable($full) ? (string) file_get_contents($full) : null;
    }
}
