<?php

declare(strict_types=1);

namespace Switon\Binding;

use ReflectionParameter;

/**
 * Contract for object-typed value resolvers.
 *
 * Use when class/interface parameters need custom resolution before container fallback.
 *
 * Guidance: Return <code>null</code> only when the resolver does not handle the parameter.
 *
 * @see \Switon\Binding\ScalarResolverInterface
 * @see \Switon\Binding\ObjectResolver
 */
interface ObjectResolverInterface
{
    /**
     * Returns whether this resolver chain owns the given object type.
     *
     * @param class-string $type
     */
    public function hasResolver(string $type): bool;

    /**
     * Resolves one object parameter value.
     *
     * Return <code>null</code> when this resolver does not handle the parameter.
     */
    public function resolve(ReflectionParameter $parameter, string $type): mixed;
}
