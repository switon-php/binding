<?php

declare(strict_types=1);

namespace Switon\Binding\Tests\Unit;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionMethod;
use Switon\Binding\ArgumentsBinder;
use Switon\Binding\Event\ActionArgumentsResolving;
use Switon\Binding\Exception\TooManyPositionalArgumentsException;
use Switon\Binding\Exception\UnsupportedParameterTypeException;
use Switon\Binding\ObjectResolverInterface;
use Switon\Binding\ScalarResolverInterface;
use Switon\Binding\Tests\TestCase;
use Switon\Binding\Tests\Unit\Fixtures\BindingDependency;
use Switon\Binding\Tests\Unit\Fixtures\BindingFixtures;
use Switon\Binding\Tests\Unit\Fixtures\BindingResolvedArgument;
use Switon\Binding\Tests\Unit\Fixtures\ContainerFallbackService;
use Switon\Binding\Tests\Unit\Fixtures\PlainBindObject;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\InputInterface;
use Switon\Core\PositionalInputInterface;
use Switon\Core\MakerInterface;
use Switon\Validating\Attribute\Type;
use Switon\Validating\Exception\ValidateFailedException;
use Switon\Validating\Validation;
use Switon\Validating\ValidatorInterface;
use ReflectionParameter;

final class ArgumentsBinderTest extends TestCase
{
    protected function createBinder(
        ?ScalarResolverInterface  $scalarResolver = null,
        ?ObjectResolverInterface  $objectResolver = null,
        ?ContainerInterface       $container = null,
        ?ValidatorInterface       $validator = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?MakerInterface           $maker = null,
    ): ArgumentsBinder {
        $binder = new class () extends ArgumentsBinder {
            public function setScalarResolver(ScalarResolverInterface $scalarResolver): void
            {
                $this->scalarResolver = $scalarResolver;
            }

            public function setObjectResolver(ObjectResolverInterface $objectResolver): void
            {
                $this->objectResolver = $objectResolver;
            }

            public function setContainer(ContainerInterface $container): void
            {
                $this->container = $container;
            }

            public function setValidator(ValidatorInterface $validator): void
            {
                $this->validator = $validator;
            }

            public function setEventDispatcher(EventDispatcherInterface $eventDispatcher): void
            {
                $this->eventDispatcher = $eventDispatcher;
            }

            public function setMaker(MakerInterface $maker): void
            {
                $this->maker = $maker;
            }
        };

        $binder->setScalarResolver($scalarResolver ?? $this->createStub(ScalarResolverInterface::class));
        $binder->setObjectResolver($objectResolver ?? $this->createStub(ObjectResolverInterface::class));
        $binder->setContainer($container ?? $this->createStub(ContainerInterface::class));
        $binder->setValidator($validator ?? $this->createStub(ValidatorInterface::class));
        $binder->setEventDispatcher($eventDispatcher ?? $this->createStub(EventDispatcherInterface::class));
        $binder->setMaker($maker ?? $this->container->get(MakerInterface::class));

        return $binder;
    }

    public function testResolveUsesEventNullPrefillAsResolvedValue(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withString');

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->never())->method('resolve');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $event): ?object {
                if ($event instanceof ActionArgumentsResolving) {
                    $event->arguments[0] = null;
                }

                return null;
            });

        $validation = $this->createMock(Validation::class);
        $validation->expects($this->never())->method('validate');

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->never())->method('beginValidate');
        $validator->expects($this->never())->method('endValidate');

        $binder = $this->createBinder($scalarResolver, null, null, $validator, $eventDispatcher);
        $result = $binder->resolve($method);

        self::assertNull($result[0]);
    }

    public function testResolveUsesNamedEventArgumentBeforeOtherResolvers(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withString');

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->never())->method('resolve');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $event): ?object {
                if ($event instanceof ActionArgumentsResolving) {
                    $event->arguments['name'] = 'from-event';
                }

                return null;
            });

        $binder = $this->createBinder($scalarResolver, null, null, $this->createStub(ValidatorInterface::class), $eventDispatcher);
        $result = $binder->resolve($method);

        self::assertSame('from-event', $result[0]);
    }

    public function testResolveThrowsWhenObjectResolverReturnsNullForNonNullableParameter(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withDependency');

        $objectResolver = $this->createMock(ObjectResolverInterface::class);
        $objectResolver->expects($this->once())->method('hasResolver')->willReturn(true);
        $objectResolver->expects($this->once())->method('resolve')->willReturn(null);

        $binder = $this->createBinder(
            $this->createStub(ScalarResolverInterface::class),
            $objectResolver,
            $this->createStub(ContainerInterface::class),
            $this->createStub(ValidatorInterface::class),
            $this->createStub(EventDispatcherInterface::class)
        );

        $this->expectException(RuntimeException::class);
        $binder->resolve($method);
    }

    public function testResolveUsesObjectResolverInstanceWhenHasResolverReturnsTrue(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withDependency');
        $dependency = new BindingDependency('from-object-resolver');

        $objectResolver = $this->createMock(ObjectResolverInterface::class);
        $objectResolver->expects($this->once())->method('hasResolver')->with(BindingDependency::class)->willReturn(true);
        $objectResolver->expects($this->once())->method('resolve')->willReturn($dependency);

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->never())->method('has');
        $container->expects($this->never())->method('get');

        $binder = $this->createBinder(
            $this->createStub(ScalarResolverInterface::class),
            $objectResolver,
            $container,
            $this->createStub(ValidatorInterface::class),
            $this->createStub(EventDispatcherInterface::class),
        );

        self::assertSame($dependency, $binder->resolve($method)[0]);
    }

    public function testResolveThrowsForTooManyPositionalArguments(): void
    {
        $input = new class (['a', 'b']) implements InputInterface, PositionalInputInterface {
            public function __construct(private array $positional)
            {
            }

            public function has(string|int $name): bool
            {
                return false;
            }

            public function get(string|int $name, mixed $default = null): mixed
            {
                return $default;
            }

            public function all(): array
            {
                return [];
            }

            public function getPositional(): array
            {
                return $this->positional;
            }
        };

        $scalarResolver = new class () extends \Switon\Binding\ScalarResolver {
            public function setInput(InputInterface $input): void
            {
                $this->input = $input;
            }
        };
        $scalarResolver->setInput($input);

        $binder = $this->createBinder($scalarResolver);
        $method = new ReflectionMethod(BindingFixtures::class, 'singleString');

        $this->expectException(TooManyPositionalArgumentsException::class);
        $binder->resolve($method);
    }

    public function testResolveThrowsOnUnsupportedUnionType(): void
    {
        $binder = $this->createBinder();
        $method = new ReflectionMethod(BindingFixtures::class, 'withUnion');

        $this->expectException(UnsupportedParameterTypeException::class);
        $binder->resolve($method);
    }

    public function testResolveUsesArgumentResolvableStaticFactory(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withResolvable');

        $binder = $this->createBinder(
            $this->createStub(ScalarResolverInterface::class),
            $this->createStub(ObjectResolverInterface::class),
            $this->createStub(ContainerInterface::class),
            $this->createStub(ValidatorInterface::class),
            $this->createStub(EventDispatcherInterface::class)
        );

        $resolved = $binder->resolve($method);

        self::assertInstanceOf(BindingResolvedArgument::class, $resolved[0]);
    }

    public function testResolveUsesDefaultScalarWhenResolverReturnsNull(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withDefaultString');

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->once())->method('resolve')->willReturn(null);

        $binder = $this->createBinder(
            $scalarResolver,
            $this->createStub(ObjectResolverInterface::class),
            $this->createStub(ContainerInterface::class),
            $this->createStub(ValidatorInterface::class),
            $this->createStub(EventDispatcherInterface::class)
        );

        $resolved = $binder->resolve($method);

        self::assertSame('fallback-name', $resolved[0]);
    }

    public function testResolveFallsBackToContainerWhenObjectResolverDoesNotHandleType(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withContainerFallback');

        $service = new ContainerFallbackService();

        $objectResolver = $this->createMock(ObjectResolverInterface::class);
        $objectResolver->expects($this->once())->method('hasResolver')->willReturn(false);

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())->method('has')->with(ContainerFallbackService::class)->willReturn(true);
        $container->expects($this->once())->method('get')->with(ContainerFallbackService::class)->willReturn($service);

        $binder = $this->createBinder(
            $this->createStub(ScalarResolverInterface::class),
            $objectResolver,
            $container,
            $this->createStub(ValidatorInterface::class),
            $this->createStub(EventDispatcherInterface::class)
        );

        $resolved = $binder->resolve($method);

        self::assertSame($service, $resolved[0]);
    }

    public function testResolveReturnsNullForNullableScalarWithoutRequiredAttributeWhenMissing(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withNullableLabel');

        $binder = $this->createBinder(
            $this->createStub(ScalarResolverInterface::class),
            $this->createStub(ObjectResolverInterface::class),
            $this->createStub(ContainerInterface::class),
            $this->createStub(ValidatorInterface::class),
            $this->createStub(EventDispatcherInterface::class)
        );

        $resolved = $binder->resolve($method);

        self::assertNull($resolved[0]);
    }

    public function testResolveThrowsWhenRequiredScalarIsMissing(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withMissingString');

        $validator = $this->container->get(ValidatorInterface::class);

        $binder = $this->createBinder(
            $this->createStub(ScalarResolverInterface::class),
            $this->createStub(ObjectResolverInterface::class),
            $this->createStub(ContainerInterface::class),
            $validator,
            $this->createStub(EventDispatcherInterface::class)
        );

        $this->expectException(ValidateFailedException::class);
        $binder->resolve($method);
    }

    public function testResolveThrowsWhenTypedScalarValidationFails(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withTypedCount');

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->once())->method('resolve')->willReturn('abc');

        $binder = $this->createBinder(
            $scalarResolver,
            $this->createStub(ObjectResolverInterface::class),
            $this->createStub(ContainerInterface::class),
            $this->container->get(ValidatorInterface::class),
            $this->createStub(EventDispatcherInterface::class)
        );

        $this->expectException(ValidateFailedException::class);
        $binder->resolve($method);
    }

    public function testResolveAppliesCustomConstraintAfterTypeValidation(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withValidatedLabel');

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->once())->method('resolve')->willReturn('');

        $binder = $this->createBinder(
            $scalarResolver,
            $this->createStub(ObjectResolverInterface::class),
            $this->createStub(ContainerInterface::class),
            $this->container->get(ValidatorInterface::class),
            $this->createStub(EventDispatcherInterface::class)
        );

        $this->expectException(ValidateFailedException::class);
        $binder->resolve($method);
    }

    public function testResolveThrowsWhenExplicitRequiredNullableScalarIsMissing(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withOptionalLabel');

        $validator = $this->container->get(ValidatorInterface::class);

        $binder = $this->createBinder(
            $this->createStub(ScalarResolverInterface::class),
            $this->createStub(ObjectResolverInterface::class),
            $this->createStub(ContainerInterface::class),
            $validator,
            $this->createStub(EventDispatcherInterface::class)
        );

        $this->expectException(ValidateFailedException::class);
        $binder->resolve($method);
    }

    public function testResolveUsesNullWhenOptionalObjectResolverReturnsNullForNullableParameter(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withOptionalDependency');

        $objectResolver = $this->createMock(ObjectResolverInterface::class);
        $objectResolver->expects($this->once())->method('hasResolver')->with(BindingDependency::class)->willReturn(true);
        $objectResolver->expects($this->once())->method('resolve')->willReturn(null);

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->exactly(2))->method('dispatch');

        $binder = $this->createBinder(
            $this->createStub(ScalarResolverInterface::class),
            $objectResolver,
            $this->createStub(ContainerInterface::class),
            $this->createStub(ValidatorInterface::class),
            $eventDispatcher
        );

        $resolved = $binder->resolve($method);

        self::assertNull($resolved[0]);
    }

    public function testResolveFillsScalarParameterFromScalarResolverAfterValidation(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withString');

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->once())->method('resolve')->willReturn('resolved-name');

        $validation = $this->createMock(Validation::class);
        $validation->expects($this->atLeastOnce())->method('validate')->willReturn(true);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->once())->method('beginValidate')->willReturn($validation);
        $validator->expects($this->once())->method('endValidate');

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->exactly(2))->method('dispatch');

        $binder = $this->createBinder($scalarResolver, null, null, $validator, $eventDispatcher);
        $resolved = $binder->resolve($method);

        self::assertSame(['resolved-name'], $resolved);
    }

    public function testResolveFillsSecondScalarFromDefaultWhenEventPrefillsFirstOnly(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withTwoDefaults');

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->once())
            ->method('resolve')
            ->willReturnCallback(static function (ReflectionParameter $p): ?string {
                return $p->getName() === 'first' ? 'from-scalar' : null;
            });

        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->expects($this->exactly(2))
            ->method('dispatch')
            ->willReturnCallback(static function (object $event): ?object {
                if ($event instanceof ActionArgumentsResolving) {
                    $event->arguments['first'] = 'from-event';
                }

                return null;
            });

        $validation = $this->createStub(Validation::class);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->once())->method('beginValidate')->willReturn($validation);
        $validator->expects($this->once())->method('endValidate');

        $binder = $this->createBinder($scalarResolver, null, null, $validator, $eventDispatcher);
        $resolved = $binder->resolve($method);

        self::assertSame(['from-event', 'fb'], $resolved);
    }

    public function testResolveUsesMakerToInstantiateTypeConstraint(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withTypedLabel');

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->once())->method('resolve')->willReturn('ok');

        $maker = $this->createMock(MakerInterface::class);
        $maker->expects($this->atLeastOnce())
            ->method('make')
            ->willReturn(new Type('string'));

        $validation = $this->createMock(Validation::class);
        $validation->expects($this->atLeastOnce())->method('validate')->willReturn(true);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->once())->method('beginValidate')->willReturn($validation);
        $validator->expects($this->once())->method('endValidate');

        $binder = $this->createBinder(
            $scalarResolver,
            null,
            null,
            $validator,
            $this->createStub(EventDispatcherInterface::class),
            $maker,
        );

        $resolved = $binder->resolve($method);

        self::assertSame(['ok'], $resolved);
    }

    public function testResolveReturnsEmptyListForParameterlessMethod(): void
    {
        $binder = $this->createBinder();
        $method = new ReflectionMethod(BindingFixtures::class, 'noop');

        self::assertSame([], $binder->resolve($method));
    }

    public function testResolveMapsUnresolvedObjectParameterToNullWhileBindingScalars(): void
    {
        $method = new ReflectionMethod(BindingFixtures::class, 'withPlainObjectAndString');

        $scalarResolver = $this->createMock(ScalarResolverInterface::class);
        $scalarResolver->expects($this->once())->method('resolve')->willReturn('label-value');

        $objectResolver = $this->createMock(ObjectResolverInterface::class);
        $objectResolver->expects($this->once())->method('hasResolver')->with(PlainBindObject::class)->willReturn(false);

        $container = $this->createMock(ContainerInterface::class);
        $container->expects($this->once())->method('has')->with(PlainBindObject::class)->willReturn(false);

        $validation = $this->createStub(Validation::class);
        $validation->method('validate')->willReturn(true);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects($this->once())->method('beginValidate')->willReturn($validation);
        $validator->expects($this->once())->method('endValidate');

        $binder = $this->createBinder(
            $scalarResolver,
            $objectResolver,
            $container,
            $validator,
            $this->createStub(EventDispatcherInterface::class),
        );

        $resolved = $binder->resolve($method);

        self::assertNull($resolved[0]);
        self::assertSame('label-value', $resolved[1]);
    }
}
