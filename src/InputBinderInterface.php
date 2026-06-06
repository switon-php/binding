<?php

declare(strict_types=1);

namespace Switon\Binding;

use ReflectionProperty;

/**
 * Binds array input data into a typed input object and validates attribute constraints.
 *
 * Use when resolvers should share one typed-input binding and validation engine.
 *
 * Guidance: Treat explicit <code>null</code> values as missing fields (same semantics as entity binding).
 *
 * Road-signs:
 * - array source only
 * - public-property hydration
 * - validation happens during binding
 *
 * @see \Switon\Binding\InputBinder
 */
interface InputBinderInterface
{
    /**
     * @param class-string $type Input class name
     * @param array<string, mixed> $source Input source data
     *
     * Explicit null values are treated as "not provided" when determining Required/type behavior.
     *
     * @return object Bound input object
     */
    public function bind(string $type, array $source): object;

    /**
     * Normalize one raw property value using property attributes.
     *
     * @param ReflectionProperty $property Target property metadata
     * @param mixed $value Raw input value
     *
     * @return mixed Normalized value
     */
    public function normalizePropertyInput(ReflectionProperty $property, mixed $value): mixed;
}
