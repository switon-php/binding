<?php

declare(strict_types=1);

namespace Switon\Binding;

use ReflectionParameter;

/**
 * Contract for scalar value resolvers.
 *
 * Use when primitive parameters are resolved from request or CLI input sources.
 *
 * Guidance: Return <code>null</code> only when the resolver does not handle the parameter.
 *
 * @see \Switon\Binding\ObjectResolverInterface
 * @see \Switon\Binding\ScalarResolver
 */
interface ScalarResolverInterface
{
    /**
     * Resolves one scalar parameter value.
     *
     * Return <code>null</code> when this resolver does not handle the parameter.
     */
    public function resolve(ReflectionParameter $parameter, string $type): mixed;
}
