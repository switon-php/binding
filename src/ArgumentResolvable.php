<?php

declare(strict_types=1);

namespace Switon\Binding;

use Psr\Container\ContainerInterface;

/**
 * Defines self-resolving contract for parameter-bound types.
 *
 * Use when a parameter type should construct itself from container state.
 *
 * Guidance: Use this for object construction hooks, not for general transport parsing.
 *
 * Road-signs:
 * - action parameters
 * - self-resolve
 * - ResolvedBy
 * - object resolver chain
 * - container fallback
 *
 * @see \Switon\Binding\ArgumentsBinder::resolve()
 * @see \Switon\Binding\Attribute\ResolvedBy
 * @see \Switon\Binding\ValueResolverInterface
 */
interface ArgumentResolvable
{
    /**
     * Resolves an argument value from the container.
     *
     * @param ContainerInterface $container Dependency injection container
     *
     * @return mixed Resolved argument value
     */
    public static function argumentResolve(ContainerInterface $container): mixed;
}
