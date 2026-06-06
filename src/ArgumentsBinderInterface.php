<?php

declare(strict_types=1);

namespace Switon\Binding;

use ReflectionMethod;

/**
 * Defines contract for binding method arguments before invocation.
 *
 * Use when arguments may come from event hooks, custom resolvers, container fallback,
 * and scalar input resolution.
 *
 * This contract turns one reflected method into one final argument list that matches
 * declaration order, so callers do not need to understand where each value came from.
 *
 * Resolution order:
 * - pre-fill from <code>ActionArgumentsResolving</code> listeners
 * - resolve objects via self-resolving types, object resolvers, then container fallback
 * - resolve scalars via scalar resolvers, defaults, and required validation
 * - post-process final argument list in <code>ActionArgumentsResolved</code>
 *
 * Common resolution sources:
 * - service container dependencies
 * - CLI named, short, and positional input
 * - request-body typed-input mapping
 * - custom scalar and object value resolvers
 *
 * Guidance: Customize parameter sources through resolvers and events instead of forking invocation logic per transport.
 *
 * Road-signs:
 * - prefill and finalization both flow through events
 * - reflection order is preserved in the returned array
 * - resolver null means abstain; final arrays may still contain null for nullable parameters
 *
 * @see \Switon\Binding\ArgumentsBinder
 * @see \Switon\Binding\ArgumentsBinderInterface::resolve()
 * @see \Switon\Binding\Event\ActionArgumentsResolving
 * @see \Switon\Binding\Event\ActionArgumentsResolved
 */
interface ArgumentsBinderInterface
{
    /**
     * Resolves invocation arguments for a reflected method.
     *
     * @param ReflectionMethod $rMethod Method to resolve
     *
     * @return array<int, mixed> Resolved arguments in parameter order
     */
    public function resolve(ReflectionMethod $rMethod): array;
}
