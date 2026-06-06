<?php

declare(strict_types=1);

namespace Switon\Binding;

use ReflectionParameter;

/**
 * Defines contract for parameter-specific value object builders.
 *
 * Use when a value object class declares <code>#[ResolvedBy(...)]</code> and should be populated by one resolver.
 *
 * Guidance: Return <code>null</code> only when the resolver does not handle the parameter.
 *
 * @see \Switon\Binding\Attribute\ResolvedBy
 * @see \Switon\Binding\ObjectResolver::resolve()
 */
interface ValueResolverInterface
{
    /**
     * Builds the final parameter value.
     *
     * @param ReflectionParameter $parameter Reflected parameter metadata
     * @param string $type Resolved class-string type (named type only)
     *
     * @return mixed Return null when this resolver does not handle the parameter
     */
    public function resolve(ReflectionParameter $parameter, string $type): mixed;
}
