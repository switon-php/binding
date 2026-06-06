<?php

declare(strict_types=1);

namespace Switon\Binding\Tests\Unit;

use Psr\Container\ContainerInterface;
use ReflectionMethod;
use Switon\Binding\Attribute\ResolvedBy;
use Switon\Binding\ObjectResolver;
use Switon\Binding\Tests\TestCase;
use Switon\Binding\ValueResolverInterface;
use Switon\Core\Exception\RuntimeException;
use ReflectionParameter;
use stdClass;

final class ObjectResolverTest extends TestCase
{
    public function testHasResolverSkipsInterfaces(): void
    {
        $resolver = $this->createObjectResolver($this->createStub(ContainerInterface::class));

        self::assertFalse($resolver->hasResolver(ObjectResolverFixtureInterface::class));
    }

    public function testHasResolverReturnsFalseWhenClassDoesNotExist(): void
    {
        $resolver = $this->createObjectResolver($this->createStub(ContainerInterface::class));

        self::assertFalse($resolver->hasResolver('Switon\\Binding\\Tests\\Unit\\NonexistentInputType'));
    }

    public function testResolveUsesInheritedResolvedByAttribute(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with(ObjectResolverFixtureResolver::class)
            ->willReturn(new class () implements ValueResolverInterface {
                public function resolve(ReflectionParameter $parameter, string $type): mixed
                {
                    return new ObjectResolverFixtureValue('resolved');
                }
            });

        $resolver = $this->createObjectResolver($container);
        $parameter = (new ReflectionMethod(ObjectResolverFixtureReceiver::class, 'handle'))->getParameters()[0];

        $value = $resolver->resolve($parameter, $parameter->getType()->getName());

        self::assertInstanceOf(ObjectResolverFixtureValue::class, $value);
        self::assertSame('resolved', $value->value);
    }

    public function testResolveThrowsWhenResolverClassDoesNotExist(): void
    {
        $resolver = $this->createObjectResolver($this->createStub(ContainerInterface::class));
        $parameter = (new ReflectionMethod(ObjectResolverMissingReceiver::class, 'handle'))->getParameters()[0];

        $this->expectException(RuntimeException::class);
        $resolver->hasResolver($parameter->getType()->getName());
    }

    public function testResolveReturnsNullWhenTypeHasNoResolverAttribute(): void
    {
        $resolver = $this->createObjectResolver($this->createStub(ContainerInterface::class));
        $parameter = (new ReflectionMethod(ObjectResolverPlainReceiver::class, 'handle'))->getParameters()[0];

        self::assertFalse($resolver->hasResolver($parameter->getType()->getName()));
        self::assertNull($resolver->resolve($parameter, $parameter->getType()->getName()));
    }

    public function testResolveThrowsWhenContainerReturnsNonValueResolver(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())
            ->method('get')
            ->with(ObjectResolverFixtureResolver::class)
            ->willReturn(new stdClass());

        $resolver = $this->createObjectResolver($container);
        $parameter = (new ReflectionMethod(ObjectResolverFixtureReceiver::class, 'handle'))->getParameters()[0];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must implement');
        $resolver->resolve($parameter, ObjectResolverFixtureChildValue::class);
    }

    private function createObjectResolver(ContainerInterface $container): ObjectResolver
    {
        return new class ($container) extends ObjectResolver {
            public function __construct(ContainerInterface $container)
            {
                $this->container = $container;
            }
        };
    }
}

#[ResolvedBy(ObjectResolverFixtureResolver::class)]
class ObjectResolverFixtureValue
{
    public function __construct(public string $value)
    {
    }
}

#[ResolvedBy(ObjectResolverFixtureResolver::class)]
class ObjectResolverFixtureChildValue extends ObjectResolverFixtureValue
{
}

interface ObjectResolverFixtureInterface
{
}

class ObjectResolverFixtureReceiver
{
    public function handle(ObjectResolverFixtureChildValue $value): void
    {
    }
}

class ObjectResolverMissingReceiver
{
    public function handle(ObjectResolverMissingValue $value): void
    {
    }
}

class ObjectResolverPlainReceiver
{
    public function handle(ObjectResolverPlainValue $value): void
    {
    }
}

class ObjectResolverPlainValue
{
}

#[ResolvedBy('Switon\\Binding\\Tests\\Unit\\Fixtures\\MissingObjectResolver')]
class ObjectResolverMissingValue
{
}

class ObjectResolverFixtureResolver implements ValueResolverInterface
{
    public function resolve(ReflectionParameter $parameter, string $type): mixed
    {
        return new ObjectResolverFixtureValue('fixture');
    }
}
