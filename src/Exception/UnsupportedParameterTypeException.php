<?php

declare(strict_types=1);

namespace Switon\Binding\Exception;

use Switon\Binding\Exception as BaseException;

/**
 * Use when a bound method parameter declares an unsupported reflection type.
 *
 * @see \Switon\Binding\ArgumentsBinder
 */
class UnsupportedParameterTypeException extends BaseException
{
}
