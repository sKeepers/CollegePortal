<?php

namespace App\Support\Auth\Providers;

/**
 * Список подключённых внешних способов входа.
 *
 * Сейчас пуст: Telegram придёт с `AUTH-003`, MAX — с `AUTH-004`, и это не оплошность,
 * а порядок работ. Слой сделан первым, чтобы оба провайдера встали в готовое место,
 * а не принесли каждый свою схему хранения и свою проверку.
 *
 * Пустой список означает ровно одно: привязать пока нечего. Всё остальное —
 * просмотр и отвязка — работает уже сейчас.
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

    /** @return list<array{code: string, name: string}> */
    public function available(): array
    {
        return array_values(array_map(
            static fn (ExternalIdentityProvider $provider): array => ['code' => $provider->code(), 'name' => $provider->name()],
            $this->providers,
        ));
    }
}
