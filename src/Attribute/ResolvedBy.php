<?php

declare(strict_types=1);

namespace Switon\Binding\Attribute;

use Attribute;

/**
 * Declares which resolver class should build a parameter-bound value object.
 *
 * Use on value-object classes that are intended to be auto-filled during action argument resolution.
 *
 * Guidance: Attach this to the value-object class, not to controller or command methods.
 *
 * Road-signs:
 * - attach to parameter type
 * - resolver service
 * - inherit from parent
 * - fail-fast missing resolver
 * - action argument resolution
 *
 * @see \Switon\Binding\ValueResolverInterface
 * @see \Switon\Binding\ObjectResolver::resolve()
 * @see \Switon\Binding\ArgumentsBinder::resolve()
 */
#[Attribute(Attribute::TARGET_CLASS)]
class ResolvedBy
{
    /**
     * @param class-string $resolver Resolver class name
     */
    public function __construct(
        protected string $resolver
    ) {
    }

    /**
     * Returns resolver class name.
     *
     * @return class-string
     */
    public function getResolver(): string
    {
        return $this->resolver;
    }
}
