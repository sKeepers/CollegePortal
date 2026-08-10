<?php

namespace App\Support\Auth\Providers;

/**
 * Кем провайдер представил человека после проверки подписи.
 *
 * Ровно два поля: устойчивый идентификатор, по которому мы узнаём аккаунт, и то,
 * что показать человеку, чтобы он свой аккаунт узнал. Ничего больше от провайдера
 * не нужно и брать не следует: имя, фотография и телефон из мессенджера — чужие
 * персональные данные, которые порталу хранить незачем.
 */
final class ExternalIdentity
{
    public function __construct(
        public readonly string $providerUserId,
        public readonly ?string $displayName = null,
    ) {
    }
}
