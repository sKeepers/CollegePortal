<?php

namespace App\Support\Auth\Providers;

/**
 * Список подключённых внешних способов входа.
 *
 * Telegram пришёл с `AUTH-003` и подключается, только когда настроен: без имени бота
 * и токена в `.env` список снова пуст. MAX ждёт выбора владельца — «входа через MAX»
 * для стороннего сайта не существует.
 *
 * Пустой список означает ровно одно: привязать пока нечего. Всё остальное —
 * просмотр и отвязка — работает и с пустым.
 */
final class ExternalIdentityProviders
{
    /** @var array<string, ExternalIdentityProvider> */
    private array $providers = [];

    /** @param iterable<ExternalIdentityProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->providers[$provider->code()] = $provider;
        }
    }

    public function get(string $code): ?ExternalIdentityProvider
    {
        return $this->providers[$code] ?? null;
    }

    /** @return list<array{code: string, name: string, config: array<string, string>}> */
    public function available(): array
    {
        return array_values(array_map(
            static fn (ExternalIdentityProvider $provider): array => [
                'code' => $provider->code(),
                'name' => $provider->name(),
                'config' => $provider->publicConfig(),
            ],
            $this->providers,
        ));
    }
}
