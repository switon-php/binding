<?php

declare(strict_types=1);

namespace Switon\Binding;

use ReflectionNamedType;
use ReflectionParameter;
use Switon\Binding\Exception\TooManyPositionalArgumentsException;
use Switon\Core\Attribute\Autowired;
use Switon\Core\InputInterface;
use Switon\Core\PositionalInputInterface;
use Switon\Core\Naming;

use function array_slice;
use function count;
use function in_array;
use function is_array;
use function strtolower;

/**
 * Resolves scalar parameter values from <code>InputInterface</code>.
 *
 * Use when scalar parameters should support exact-name and snake_case lookup.
 *
 * Boolean values follow CLI-style flag semantics:
 * - empty flag or <code>has()</code> without value resolves to <code>true</code>
 * - textual truthy values resolve to <code>true</code>
 * - absent input resolves to <code>null</code>
 *
 * @see \Switon\Binding\ScalarResolverInterface::resolve()
 * @see \Switon\Core\InputInterface::get()
 * @see \Switon\Core\InputInterface::has()
 */
class ScalarResolver implements ScalarResolverInterface
{
    #[Autowired] protected InputInterface $input;

    public function resolve(ReflectionParameter $parameter, string $type): mixed
    {
        $name = $parameter->getName();

        $value = $this->resolveByName($name, $type);
        if ($value !== null) {
            return $value;
        }

        $parameters = $this->extractParameters($parameter);
        $parameterIndex = $this->findParameterIndex($parameters, $parameter);
        if ($parameterIndex === null) {
            return null;
        }

        $positional = $this->getPositionalArgs();
        if ($positional === []) {
            return null;
        }

        return $this->resolvePositional($parameters, $parameterIndex, $positional);
    }

    /**
     * @return array<int, ReflectionParameter>
     */
    protected function extractParameters(ReflectionParameter $parameter): array
    {
        return $parameter->getDeclaringFunction()->getParameters();
    }

    /**
     * @return array<int, mixed>
     */
    protected function getPositionalArgs(): array
    {
        if (!$this->input instanceof PositionalInputInterface) {
            return [];
        }

        return $this->input->getPositional();
    }

    protected function resolveByName(string $name, string $type): mixed
    {
        $snakeName = Naming::snake($name);
        $value = $this->input->get($name);
        if ($value === null) {
            $value = $this->input->get($snakeName);
        }

        if (is_array($value) && $type !== 'array') {
            $value = array_last($value);
        }

        if ($type === 'bool') {
            if ($value === '' || $value === null) {
                if ($value === '') {
                    return true;
                }
                if ($this->input->has($name) || $this->input->has($snakeName)) {
                    return true;
                }
                return null;
            }
            return in_array(strtolower((string)$value), ['1', 'yes', 'on', 'true'], true);
        }

        if ($value === '') {
            return $type === 'string' ? '' : null;
        }

        return $value;
    }

    /**
     * @param array<int, ReflectionParameter> $parameters
     */
    protected function findParameterIndex(array $parameters, ReflectionParameter $current): ?int
    {
        $position = $current->getPosition();
        if (isset($parameters[$position]) && $parameters[$position]->getName() === $current->getName()) {
            return $position;
        }

        foreach ($parameters as $index => $parameter) {
            if ($parameter === $current) {
                return $index;
            }
        }

        foreach ($parameters as $index => $parameter) {
            if ($parameter->getName() === $current->getName()) {
                return $index;
            }
        }
        return null;
    }

    /**
     * @param array<int, ReflectionParameter> $parameters
     * @param string[] $positional
     */
    protected function resolvePositional(array $parameters, int $currentIndex, array $positional): mixed
    {
        $unbound = [];
        foreach ($parameters as $index => $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                continue;
            }
            $name = $parameter->getName();
            $namedType = $type instanceof ReflectionNamedType ? $type->getName() : null;
            if ($this->resolveByName($name, $namedType) !== null) {
                continue;
            }
            $unbound[] = $index;
        }

        if ($unbound === []) {
            return null;
        }

        $arrayParamIdx = null;
        $lastIdx = $unbound[count($unbound) - 1];
        $lastType = $parameters[$lastIdx]->getType();
        if ($lastType instanceof ReflectionNamedType && $lastType->getName() === 'array') {
            $arrayParamIdx = $lastIdx;
        }

        if ($arrayParamIdx === null && count($positional) > count($unbound)) {
            TooManyPositionalArgumentsException::raise(
                'Too many positional arguments: expected at most {expected}, got {actual}',
                ['expected' => count($unbound), 'actual' => count($positional)]
            );
        }

        $posIndex = 0;
        foreach ($unbound as $index) {
            if ($index === $arrayParamIdx) {
                continue;
            }
            if ($posIndex >= count($positional)) {
                break;
            }
            if ($index === $currentIndex) {
                return $positional[$posIndex];
            }
            $posIndex++;
        }

        if ($arrayParamIdx !== null && $currentIndex === $arrayParamIdx) {
            $remaining = array_slice($positional, $posIndex);
            return $remaining !== [] ? $remaining : null;
        }

        return null;
    }
}
