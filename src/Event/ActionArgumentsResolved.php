<?php

declare(strict_types=1);

namespace Switon\Binding\Event;

use ReflectionMethod;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Argument-binding completion event.
 *
 * Log category: <code>switon.binding.action.arguments.resolved</code>
 *
 * @see \Switon\Binding\ArgumentsBinderInterface::resolve()
 * @see \Switon\Binding\Event\ActionArgumentsResolving
 */
#[EventLevel(Severity::DEBUG)]
class ActionArgumentsResolved
{
    /**
     * @param ReflectionMethod $method Method that will be invoked
     * @param array<int, mixed> $arguments Resolved arguments by index
     */
    public function __construct(public ReflectionMethod $method, public array $arguments)
    {
    }
}
