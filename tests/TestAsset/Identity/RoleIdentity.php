<?php

declare(strict_types=1);

namespace Contenir\Cache\Laminas\Mvc\Tests\TestAsset\Identity;

/**
 * An authenticated identity exposing the getRoleId() the listener keys on.
 */
final readonly class RoleIdentity
{
    public function __construct(
        private mixed $roleId,
    ) {}

    public function getRoleId(): mixed
    {
        return $this->roleId;
    }
}
