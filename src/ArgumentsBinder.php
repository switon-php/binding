<?php

declare(strict_types=1);

namespace Switon\Binding;

use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use ReflectionAttribute;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use stdClass;
use Switon\Binding\Event\ActionArgumentsResolved;
use Switon\Binding\Event\ActionArgumentsResolving;
use Switon\Binding\Exception\UnsupportedParameterTypeException;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\MakerInterface;
use Switon\Validating\Attribute\Required;
use Switon\Validating\Attribute\Type;
use Switon\Validating\ConstraintInterface;
use Switon\Validating\Validation;
use Switon\Validating\ValidatorInterface;
use ReflectionUnionType;

use function array_fill;
use function array_key_exists;
use function count;
use function is_subclass_of;

/**
 * Resolves method arguments from events, value resolvers, and container fallback.
 *
 * Use when dispatching actions or commands with mixed object and scalar parameters.
 *
 * Road-signs:
 * - events ActionArgumentsResolving/Resolved
 * - value resolvers
 * - container fallback
 * - short + positional
 * - required/type
 *
 * @see \Switon\Binding\ArgumentsBinderInterface
 * @see \Switon\Binding\ArgumentsBinderInterface::resolve()
 * @see \Switon\Binding\Event\ActionArgumentsResolving
 * @see \Switon\Binding\Event\ActionArgumentsResolved
 * @see \Switon\Binding\ScalarResolverInterface
 * @see \Switon\Binding\ObjectResolverInterface
 * @see \Switon\Binding\ArgumentResolvable
 * @see \Switon\Binding\Attribute\ResolvedBy
 * @see \Switon\Validating\ValidatorInterface
 */
class ArgumentsBinder implements ArgumentsBinderInterface
{
    #[Autowired] protected ContainerInterface $container;
    #[Autowired] protected ValidatorInterface $validator;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected MakerInterface $maker;

    #[Autowired] protected ScalarResolverInterface $scalarResolver;
    #[Autowired] protected ObjectResolverInterface $objectResolver;

    protected function getNamedTypeName(?ReflectionType $type): ?string
    {
        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        if ($type instanceof ReflectionUnionType) {
            $namedType = $type->getTypes()[0] ?? null;
            return $namedType instanceof ReflectionNamedType ? $namedType->getName() : null;
        }

        return null;
    }

    protected function isNamedObjectType(?ReflectionType $type): bool
    {
        return $type instanceof ReflectionNamedType && !$type->isBuiltin();
    }

    /**
     * @param list<ReflectionParameter> $rParameters
     * @param array<int, mixed> $arguments
     * @param array<int, true> $resolved
     *
     * @return array{source: array<string, mixed>, values: array<string, mixed>}
     */
    protected function collectScalarCandidates(
        array $rParameters,
        array $arguments,
        array $resolved
    ): array {
        $source = [];
        $values = [];

        foreach ($rParameters as $i => $rParameter) {
            $rType = $rParameter->getType();
            if ($this->isNamedObjectType($rType)) {
                continue;
            }

            $name = $rParameter->getName();
            if (isset($resolved[$i])) {
                $source[$name] = $arguments[$i];
                continue;
            }

            $type = $this->getNamedTypeName($rType);
            if ($type === null) {
                continue;
            }

            $value = $this->scalarResolver->resolve($rParameter, $type);
            $values[$name] = $value;
            if ($value !== null) {
                $source[$name] = $value;
                continue;
            }

            if ($rParameter->isDefaultValueAvailable()) {
                $source[$name] = $rParameter->getDefaultValue();
            }
        }

        return ['source' => $source, 'values' => $values];
    }

    protected function validateResolvedScalar(
        ReflectionParameter $rParameter,
        Validation          $validation,
        mixed               $value
    ): mixed {
        $type = $rParameter->getType()?->getName();
        $validation->field = $rParameter->getName();
        $validation->value = $value;
        $validation->targetType = $type;

        $typeConstraint = $rParameter->getAttributes(Type::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if (!$validation->validate(
            $typeConstraint !== null
                ? $this->maker->make($typeConstraint->getName(), $typeConstraint->getArguments())
                : new Type($type)
        )) {
            return null;
        }

        foreach ($rParameter->getAttributes(ConstraintInterface::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
            /** @var ConstraintInterface $constraint */
            $constraint = $this->maker->make($attribute->getName(), $attribute->getArguments());
            if ($constraint instanceof Type || $constraint instanceof Required) {
                continue;
            }

            if (!$validation->validate($constraint)) {
                return null;
            }
        }

        if (!isset($validation->source) || !is_array($validation->source)) {
            $validation->source = [];
        }
        $validation->source[$validation->field] = $validation->value;
        return $validation->value;
    }

    protected function validateMissingScalar(ReflectionParameter $rParameter, Validation $validation): void
    {
        $validation->field = $rParameter->getName();
        $validation->value = null;
        $validation->targetType = $rParameter->getType()?->getName();

        $requiredAttribute = $rParameter->getAttributes(Required::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if ($requiredAttribute !== null) {
            $validation->validate($requiredAttribute->newInstance());
            return;
        }

        if (!$rParameter->allowsNull()) {
            $validation->validate(new Required());
        }
    }

    public function resolve(ReflectionMethod $rMethod): array
    {
        if (($numOfParameters = $rMethod->getNumberOfParameters()) === 0) {
            return [];
        }

        $rParameters = $rMethod->getParameters();
        foreach ($rParameters as $rParameter) {
            $type = $rParameter->getType();
            if (!$type instanceof ReflectionNamedType) {
                UnsupportedParameterTypeException::raise(
                    'Unsupported parameter type for "{parameter}": only named types are supported.',
                    ['parameter' => $rParameter->getName()]
                );
            }
        }

        $unresolved = new stdClass();
        $arguments = array_fill(0, $numOfParameters, $unresolved);
        $resolved = [];

        $event = new ActionArgumentsResolving($rMethod, $arguments);
        $this->eventDispatcher->dispatch($event);

        foreach ($rParameters as $i => $rParameter) {
            $name = $rParameter->getName();

            if (array_key_exists($name, $event->arguments)) {
                $arguments[$i] = $event->arguments[$name];
                $resolved[$i] = true;
                continue;
            }

            if (array_key_exists($i, $event->arguments) && $event->arguments[$i] !== $unresolved) {
                $arguments[$i] = $event->arguments[$i];
                $resolved[$i] = true;
                continue;
            }

            $rType = $rParameter->getType();
            $type = $this->getNamedTypeName($rType);
            if ($type === null) {
                continue;
            }

            if ($this->isNamedObjectType($rType)) {
                if (is_subclass_of($type, ArgumentResolvable::class)) {
                    $arguments[$i] = $type::argumentResolve($this->container);
                    $resolved[$i] = true;
                    continue;
                }

                if ($this->objectResolver->hasResolver($type)) {
                    $value = $this->objectResolver->resolve($rParameter, $type);
                    if ($value !== null) {
                        $arguments[$i] = $value;
                        $resolved[$i] = true;
                        continue;
                    }

                    if ($rParameter->allowsNull()) {
                        $arguments[$i] = null;
                        $resolved[$i] = true;
                        continue;
                    }

                    RuntimeException::raise(
                        'Resolver returned null for non-nullable parameter "{parameter}" of type "{type}".',
                        ['parameter' => $name, 'type' => $type]
                    );
                }

                if ($this->container->has($type)) {
                    $arguments[$i] = $this->container->get($type);
                    $resolved[$i] = true;
                }
            }
        }

        $arguments = array_map(
            static fn (mixed $argument): mixed => $argument === $unresolved ? null : $argument,
            $arguments
        );

        if (count($resolved) === $numOfParameters) {
            $resolvedEvent = new ActionArgumentsResolved($rMethod, $arguments);
            $this->eventDispatcher->dispatch($resolvedEvent);
            return $resolvedEvent->arguments;
        }

        $collected = $this->collectScalarCandidates($rParameters, $arguments, $resolved);
        $validation = $this->validator->beginValidate($collected['source']);

        foreach ($rParameters as $i => $rParameter) {
            if (isset($resolved[$i])) {
                continue;
            }

            $rType = $rParameter->getType();
            if ($this->isNamedObjectType($rType)) {
                continue;
            }

            $name = $rParameter->getName();
            if (array_key_exists($name, $collected['values']) && $collected['values'][$name] !== null) {
                $value = $this->validateResolvedScalar($rParameter, $validation, $collected['values'][$name]);
                $arguments[$i] = $value;
                $resolved[$i] = true;
                continue;
            }

            if ($rParameter->isDefaultValueAvailable()) {
                $arguments[$i] = $rParameter->getDefaultValue();
                $resolved[$i] = true;
                continue;
            }

            $this->validateMissingScalar($rParameter, $validation);
        }

        $this->validator->endValidate($validation);

        $arguments = array_map(
            static fn (mixed $argument): mixed => $argument === $unresolved ? null : $argument,
            $arguments
        );
        $resolvedEvent = new ActionArgumentsResolved($rMethod, $arguments);
        $this->eventDispatcher->dispatch($resolvedEvent);

        return $resolvedEvent->arguments;
    }
}
