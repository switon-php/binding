<?php

declare(strict_types=1);

namespace Switon\Binding;

use Psr\Container\ContainerInterface;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionParameter;
use Switon\Binding\Attribute\ResolvedBy as ResolvedByAttribute;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\RuntimeException;

/**
 * Resolves value-object parameters declared with <code>#[ResolvedBy(...)]</code>.
 *
 * Guidance: Keep this resolver for value objects only; component services continue through container fallback.
 *
 * @see \Switon\Binding\Attribute\ResolvedBy
 * @see \Switon\Binding\ValueResolverInterface
 * @see \Switon\Binding\ObjectResolverInterface::resolve()
 */
class ObjectResolver implements ObjectResolverInterface
{
    #[Autowired] protected ContainerInterface $container;
    /** @var array<class-string, class-string|false> */
    protected array $resolvers = [];

    public function hasResolver(string $type): bool
    {
        if (interface_exists($type) || !class_exists($type)) {
            return false;
        }

        return $this->resolveCachedResolverClass($type) !== false;
    }

    public function resolve(ReflectionParameter $parameter, string $type): mixed
    {
        if (!$this->hasResolver($type)) {
            return null;
        }

        $resolverClass = $this->resolveCachedResolverClass($type);
        $resolver = $this->container->get($resolverClass);
        if (!$resolver instanceof ValueResolverInterface) {
            RuntimeException::raise(
                'Resolver "{resolver}" for "{type}" must implement {contract}.',
                ['resolver' => $resolverClass, 'type' => $type, 'contract' => ValueResolverInterface::class]
            );
        }

        return $resolver->resolve($parameter, $type);
    }

    /**
     * @param class-string $type
     *
     * @return class-string|false
     */
    protected function resolveResolverClass(string $type): string|false
    {
        $rClass = new ReflectionClass($type);

        while ($rClass !== false) {
            $attribute = $rClass->getAttributes(
                ResolvedByAttribute::class,
                ReflectionAttribute::IS_INSTANCEOF
            )[0] ?? null;

            if ($attribute !== null) {
                $instance = $attribute->newInstance();
                if (!$instance instanceof ResolvedByAttribute) {
                    RuntimeException::raise('Invalid #[ResolvedBy] on "{type}".', ['type' => $type]);
                }

                $resolver = $instance->getResolver();
                if (!class_exists($resolver)) {
                    RuntimeException::raise(
                        'Resolver class "{resolver}" for "{type}" does not exist.',
                        ['resolver' => $resolver, 'type' => $type]
                    );
                }

                return $resolver;
            }

            $rClass = $rClass->getParentClass();
        }

        return false;
    }

    /**
     * @param class-string $type
     *
     * @return class-string|false
     */
    protected function resolveCachedResolverClass(string $type): string|false
    {
        return $this->resolvers[$type] ??= $this->resolveResolverClass($type);
    }
}
