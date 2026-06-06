<?php

declare(strict_types=1);

namespace Switon\Binding\Exception;

use Switon\Binding\Exception as BaseException;

/**
 * Use when nested input binding exceeds the safety depth limit.
 *
 * @see \Switon\Binding\InputBinder
 */
class InputBindingNestingDepthExceededException extends BaseException
{
}
