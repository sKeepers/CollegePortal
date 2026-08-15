<?php

namespace App\Providers;

use App\Models\User;
use App\Models\Student;
use App\Models\Teacher;
use App\Observers\StudentObserver;
use App\Observers\TeacherObserver;
use App\Observers\UserObserver;
use App\Support\Auth\ApiTokenResolver;
use App\Support\Auth\Providers\ExternalIdentityProviders;
use App\Support\Auth\Providers\TelegramLoginProvider;
use App\Support\Notifications\MaxNotificationChannel;
use App\Support\Notifications\NotificationChannels;
use App\Support\LoginIdentifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Один резолвер на запрос: его спрашивают и ограничитель частоты, и `api.token`.
        $this->app->scoped(ApiTokenResolver::class);

        // Список внешних способов входа. Провайдер подключается только когда настроен:
        // без имени бота и токена Telegram-вход не появляется ни в списке, ни кнопкой,
        // и портал работает ровно как до `AUTH-003`. MAX встанет сюда же, если владелец
        // выберет один из путей — «входа через MAX» для стороннего сайта не существует.
        // Каналы доставки уведомлений. Без токена бота список пуст: галочки не
        // показываются, отправлять некуда, портал работает как до `NOTIFY-001`.
        // Telegram сюда не встал не по забывчивости — с боевого сервера его адреса
        // отвечают через раз, замер в docs/NOTIFY_001_PLAN.md.
        // Сам канал разрешается всегда: его зовёт и служба привязки, которой нужна
        // очередь обновлений. А вот в список подключённых он попадает только с токеном —
        // список и решает, есть ли уведомления вообще.
        $this->app->singleton(MaxNotificationChannel::class, fn (): MaxNotificationChannel => new MaxNotificationChannel(
            (string) (config('services.max.bot_token') ?: ''),
        ));

        $this->app->singleton(NotificationChannels::class, function (): NotificationChannels {
            $token = config('services.max.bot_token');

            return new NotificationChannels(array_filter([
                $token ? $this->app->make(MaxNotificationChannel::class) : null,
            ]));
        });

        $this->app->singleton(ExternalIdentityProviders::class, function (): ExternalIdentityProviders {
            $token = config('services.telegram.bot_token');
            $username = config('services.telegram.bot_username');

            return new ExternalIdentityProviders(array_filter([
                $token && $username ? new TelegramLoginProvider($token, $username) : null,
            ]));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Student::observe(StudentObserver::class);
        Teacher::observe(TeacherObserver::class);
        User::observe(UserObserver::class);

        // Считать попытки нужно по тому же значению, по которому контроллер ищет
        // учетную запись. Раньше ключ собирался из поля email, которого форма
        // входа не отправляет ни разу — она отправляет login. Ключ сводился к
        // одному адресу, поэтому пятеро ошибившихся подряд закрывали вход всем
        // остальным с того же адреса, а подбор пароля к конкретной учетной
        // записи не ограничивался ничем.
        //
        // Счетчиков два, потому что защищают они от разного: по учетной записи —
        // от подбора пароля, по адресу — от перебора учетных записей с одной
        // машины. Любой одиночный ключ жертвует одним из двух.
        RateLimiter::for('auth.login', function (Request $request) {
            $login = LoginIdentifier::canonical((string) ($request->input('login') ?: $request->input('email')));

            return [
                Limit::perMinute(5)->by('login|'.($login !== '' ? $login : $request->ip())),
                // Порог по адресу намеренно щедрый: за общим NAT сидит весь
                // колледж, и утренний вход не должен упираться в счетчик.
                // Подбор пароля останавливает не он, а счетчик выше.
                Limit::perMinute(60)->by('ip|'.$request->ip()),
            ];
        });

        // Вход через внешний способ. Считаем по адресу и только по нему: ключом из
        // присланного payload воспользоваться нельзя — на момент подсчёта подпись ещё
        // не проверена, и подбирающий менял бы идентификатор, получая каждому запросу
        // свой счётчик. Та же ловушка, что в SEC-006 с токеном.
        //
        // Порог щедрее, чем у входа паролем: подобрать подпись HMAC нельзя, и счётчик
        // здесь стоит против шума, а не против подбора. За общим NAT сидит весь колледж,
        // и пять попыток в минуту на всех оказались бы теснее, чем нужно.
        RateLimiter::for('auth.external', fn (Request $request) => Limit::perMinute(30)->by('ip|'.$request->ip()));

        RateLimiter::for('api.authenticated', function (Request $request) {
            // Считаем по человеку, а не по адресу: снаружи весь колледж приходит через
            // один NAT, и общий счётчик несколько одновременно работающих выбивали бы
            // друг у друга. `$request->user()` здесь спрашивать бесполезно —
            // ограничитель по приоритету идёт раньше `api.token`, — поэтому владельца
            // токена берём у общего резолвера; он же отдаст готовый ответ middleware.
            //
            // Неопознанный токен считается по адресу. Выводить ключ прямо из токена
            // нельзя: перебор случайных значений давал бы каждому запросу собственный
            // счётчик и снимал ограничение вовсе.
            $user = app(ApiTokenResolver::class)->resolve($request);

            // Порог не менялся. Он и был рассчитан на одного человека — просто
            // доставался всему адресу сразу.
            return Limit::perMinute(120)->by($user ? 'user|'.$user->id : 'ip|'.$request->ip());
        });

        Gate::before(function (User $user): ?bool {
            return $user->hasRole('admin') ? true : null;
        });

        Gate::define('permission', fn (User $user, string $permission): bool => $user->hasPermission($permission));
    }
}
