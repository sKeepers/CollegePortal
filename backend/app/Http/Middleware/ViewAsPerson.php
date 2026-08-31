<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Портал глазами другого человека. Только просмотр.
 *
 * Администратор смотрит разделы так, как их видит конкретный человек, не зная
 * его пароля и ничего под ним не делая. Смотрим **глазами человека, а не
 * роли**: у роли нет ни группы, ни оценок, ни комнат, и экраны на ней пусты —
 * шесть дефектов, найденных соседней областью на живом человеке, на пустой роли
 * не видны вовсе.
 *
 * **Почему подмена стоит здесь, а не в `ApiTokenResolver`.** Резолвер отвечает
 * на вопрос «чей это токен», и его же спрашивает ограничитель частоты — потому
 * что сортировщик Laravel выносит `ThrottleRequests` вперёд нашей
 * аутентификации (`ThrottleRequests` есть в таблице приоритетов,
 * `AuthenticateApiToken` нет). Подмени владельца в резолвере — и ограничитель
 * начнёт считать частоту **просматриваемому**, хотя запросы шлёт
 * администратор. Здесь, после `api.token`, ограничитель уже отработал и
 * посчитал настоящего. Закреплено `ViewAsPersonTest`.
 *
 * Продление токена в `AuthenticateApiToken` тоже не задето: оно идёт после
 * `$next($request)`, но по переменной, взятой **до** подмены, — то есть
 * продлевается сессия администратора, а не просматриваемого.
 *
 * Разбор целиком — `docs/VIEW_AS_PERSON.md`.
 */
class ViewAsPerson
{
    /** Настоящий администратор, отложенный для журнала аудита. */
    public const IMPERSONATOR = 'view_as.impersonator';

    /**
     * Под чужими глазами проходят только эти методы.
     *
     * Списка «безопасных POST» здесь нет намеренно, и это замер, а не
     * осторожность: маршрутов `POST` в портале 175, около двадцати названы как
     * чтение — `preview`, `check`, `validate`, — но `admin/import/preview`
     * вызывает `createPreview(...)` и **заводит запись**. Слово «предпросмотр»
     * в имени маршрута чтения не означает, поэтому список, составленный по
     * именам, однажды пропустит пишущий маршрут и пропустит молча.
     *
     * @var list<string>
     */
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    /**
     * Разрешены ли выгрузки под чужими глазами.
     *
     * **Нет — решение владельца от 31.08.2026, дословно «пока что запретим».**
     * Слово «пока что» и есть причина, по которой запрет собран в одну
     * константу и два обращения к ней в этом файле, а не рассыпан проверками по
     * контроллерам выгрузок: снять его должно быть можно **переставив здесь
     * `true`**, а не вылавливая через месяц половину мест и получая портал, где
     * часть выгрузок разрешена, часть нет, и никто не помнит почему.
     *
     * Довод владельца: администратор может выгрузить то же самое **от своего
     * имени**, значит запрет у него ничего не отнимает; а разрешение добавляет
     * вопрос «чьи это данные в файле», на который потом никто не ответит.
     */
    private const EXPORTS_ALLOWED = false;

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user();

        if ($admin === null || $admin->viewing_as_user_id === null) {
            return $next($request);
        }

        $target = User::query()->where('is_active', true)->find($admin->viewing_as_user_id);

        // Человека выключили или удалили, пока на него смотрели. Режим снимается
        // сам: иначе администратор остался бы в состоянии, из которого не видно
        // выхода, и портал выглядел бы сломанным.
        if ($target === null) {
            $admin->forceFill(['viewing_as_user_id' => null])->save();

            return $next($request);
        }

        // Выход обязан работать изнутри режима. Иначе «Выйти» на полосе —
        // единственная кнопка, которую портал сам же и отвергает, а человек
        // остаётся запертым в чужих глазах. Подмены здесь нет намеренно: эти
        // маршруты выполняются от имени администратора и им нужен он сам.
        if ($this->isTheWayOut($request)) {
            return $next($request);
        }

        if (! in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $this->refuse('Портал открыт глазами другого человека — это только просмотр. Выйдите из режима, чтобы вносить изменения.');
        }

        if ($this->isExport($request)) {
            return $this->refuse('Выгрузка под чужими глазами запрещена. Выйдите из режима и выгрузите от своего имени.');
        }

        $request->attributes->set(self::IMPERSONATOR, $admin);

        Auth::setUser($target);
        $request->setUserResolver(fn () => $target);

        $response = $next($request);

        return $this->refuseAFileLeaving($response);
    }

    /**
     * Маршруты, которые режим пропускает не подменяя.
     *
     * Их ровно три и они все про выход: выход из портала, выход из режима и
     * переход к другому человеку. Все изменяющие — то есть запрет на запись
     * отверг бы их первыми, и выбраться стало бы нельзя.
     */
    private function isTheWayOut(Request $request): bool
    {
        return $request->is('api/admin/view-as', 'api/admin/view-as/*', 'api/auth/logout');
    }

    /**
     * Отдаёт ли этот адрес файл.
     *
     * Замер 31.08.2026: маршрутов со словом «выгрузка» 31, из них **29 отдают
     * файл по `GET`** и два `POST` лишь помечают пакет выгруженным. То есть
     * запрет по методу не закрыл бы **ни одной** настоящей выгрузки — выглядел
     * бы запретом и не запрещал.
     *
     * **Слова `export` тоже мало, и это не догадка — сторож нашёл семь дыр.**
     * Первая редакция ловила только `export`, и мимо неё уходили
     * `fis/outbound/packages/{package}/download` (пакет ФИС целиком),
     * `admissions/document-files/{file}/download` и файлы журнала занятия. То
     * есть запрет пропускал ровно то, что опаснее всего. Поэтому признак
     * широкий: `export`, `download` и адрес, кончающийся расширением файла.
     *
     * Полагаться на имя всё равно нельзя до конца — поэтому за этим стоит
     * второй заслон по самому ответу, `refuseAFileLeaving()`.
     */
    private function isExport(Request $request): bool
    {
        if (self::EXPORTS_ALLOWED) {
            return false;
        }

        return self::pathHandsOutAFile($request->path());
    }

    /**
     * Открыто для сторожа: он обходит маршруты роутера этим же правилом и
     * падает на появившемся непокрытом. Правило одно на обоих, потому что два
     * списка с одним смыслом разойдутся молча.
     */
    public static function pathHandsOutAFile(string $path): bool
    {
        return str_contains($path, 'export')
            || str_contains($path, 'download')
            || (bool) preg_match('/\.(csv|json|xlsx|xls|pdf|zip)$/', $path);
    }

    /**
     * Второй заслон: ответ, отдающий файл вложением.
     *
     * Нужен на случай выгрузки, названной иначе, — `download`, `sheet`, что
     * угодно. Здесь контроллер уже отработал, и след в журнале успел записаться:
     * то есть журнал скажет о выгрузке, которой человек не получил. Размен
     * назван честно и выбран в эту сторону — **файл наружу не уходит**, а
     * лишняя запись в журнале разбирается по `viewed_as_user_id`. Для всех
     * известных сегодня выгрузок сюда не доходит: их ловит `isExport()` до
     * контроллера.
     */
    private function refuseAFileLeaving(Response $response): Response
    {
        if (self::EXPORTS_ALLOWED) {
            return $response;
        }

        $disposition = (string) $response->headers->get('Content-Disposition');

        if (! str_contains(strtolower($disposition), 'attachment')) {
            return $response;
        }

        return $this->refuse('Выгрузка под чужими глазами запрещена. Выйдите из режима и выгрузите от своего имени.');
    }

    private function refuse(string $message): Response
    {
        return response()->json(['message' => $message], Response::HTTP_FORBIDDEN);
    }
}
