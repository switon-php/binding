<?php

declare(strict_types=1);

namespace Switon\Binding\Exception;

use Switon\Binding\Exception as BaseException;

/**
 * Use when positional CLI arguments exceed unresolved scalar parameters.
 *
 * @see \Switon\Binding\ArgumentsBinder
 * @see \Switon\Binding\ArgumentsBinderInterface::resolve()
 */
class TooManyPositionalArgumentsException extends BaseException
{
}
