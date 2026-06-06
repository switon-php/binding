<?php

declare(strict_types=1);

namespace Switon\Binding\Tests\Unit\Fixtures;

use Psr\Container\ContainerInterface;
use Switon\Binding\ArgumentResolvable;
use Switon\Binding\Attribute\ResolvedBy;
use Switon\Binding\ValueResolverInterface;
use Switon\Validating\Attribute\NotEmpty;
use Switon\Validating\Attribute\Required;
use Switon\Validating\Attribute\Type;
use ReflectionParameter;

final class BindingFixtures
{
    public function withString(string $name): void
    {
    }

    public function withDependency(BindingDependency $dependency): void
    {
    }

    public function singleString(string $name): void
    {
    }

    public function withUnion(string|int $value): void
    {
    }

    public function withResolvable(BindingResolvedArgument $arg): void
    {
    }

    public function withContainerFallback(ContainerFallbackService $svc): void
    {
    }

    public function withOptionalDependency(?BindingDependency $dependency): void
    {
    }

    public function withMissingString(string $name): void
    {
    }

    public function withOptionalLabel(#[Required] ?string $label): void
    {
    }

    public function withDefaultString(string $name = 'fallback-name'): void
    {
    }

    public function withNullableLabel(?string $label): void
    {
    }

    public function withTypedLabel(#[Type('string')] string $label): void
    {
    }

    public function withValidatedLabel(#[Type('string'), NotEmpty] string $label): void
    {
    }

    public function withTypedCount(#[Type('int')] int $count): void
    {
    }

    public function withTwoDefaults(string $first = 'fa', string $second = 'fb'): void
    {
    }

    public function withPlainObjectAndString(PlainBindObject $dep, string $label): void
    {
    }

    public function noop(): void
    {
    }
}

final class ContainerFallbackService
{
}

#[ResolvedBy(BindingDependencyResolver::class)]
final class BindingDependency
{
    public function __construct(public string $value = 'default-dependency')
    {
    }
}

final class BindingDependencyResolver implements ValueResolverInterface
{
    public function resolve(ReflectionParameter $parameter, string $type): mixed
    {
        return new BindingDependency();
    }
}

final class PlainBindObject
{
}

final class BindingResolvedArgument implements ArgumentResolvable
{
    public static function argumentResolve(ContainerInterface $container): mixed
    {
        return new self();
    }
}
