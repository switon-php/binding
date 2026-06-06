<?php

declare(strict_types=1);

namespace Switon\Binding;

use ReflectionClass;
use ReflectionAttribute;
use ReflectionProperty;
use Switon\Binding\Exception\InputBindingNestingDepthExceededException;
use Switon\Core\Attribute\Autowired;
use Switon\Core\MakerInterface;
use Switon\Validating\Attribute\ArrayOf;
use Switon\Validating\Attribute\Date;
use Switon\Validating\Attribute\Required;
use Switon\Validating\Attribute\Type;
use Switon\Validating\ConstraintInterface;
use Switon\Validating\Validation;
use Switon\Validating\ValidatorInterface;

/**
 * Shared typed-input binding engine for array-backed input sources.
 *
 * Guidance: Keep source selection in resolvers; this binder only handles type mapping and validation.
 *
 * @see \Switon\Binding\InputBinderInterface
 * @see \Switon\Validating\ValidatorInterface
 */
class InputBinder implements InputBinderInterface
{
    #[Autowired] protected ValidatorInterface $validator;
    #[Autowired] protected MakerInterface $maker;

    /** Maximum nesting depth to prevent infinite recursion */
    protected const int MAX_NESTING_DEPTH = 10;

    /**
     * @param class-string $type
     * @param array<string, mixed> $source
     */
    public function bind(string $type, array $source): object
    {
        $validation = $this->validator->beginValidate($source);

        $input = $this->populate($type, $source, $validation, '', 0);

        $this->validator->endValidate($validation);

        return $input;
    }

    /**
     * Validates required state for one typed-input property.
     */
    protected function validateRequired(Validation $validation, ReflectionProperty $rProperty): void
    {
        $requiredAttr = $rProperty->getAttributes(Required::class)[0] ?? null;

        if ($requiredAttr !== null) {
            $validation->validate($requiredAttr->newInstance());
            return;
        }

        $validation->validate(new Required());
    }

    /**
     * {@inheritDoc}
     */
    public function normalizePropertyInput(ReflectionProperty $property, mixed $value): mixed
    {
        foreach ($property->getAttributes() as $attribute) {
            $attributeClass = $attribute->getName();
            if (!method_exists($attributeClass, 'normalizeInput')) {
                continue;
            }

            $normalizer = $this->maker->make($attributeClass, $attribute->getArguments());
            $value = $normalizer->normalizeInput($property, $value);
        }

        return $value;
    }

    /**
     * Populates one typed input object and validates fields at the current level.
     *
     * @param class-string $type Input class name
     * @param array<string, mixed> $data Source data for current object
     * @param Validation $validation Shared validation context
     * @param string $path Current field path
     * @param int $depth Current nesting depth
     *
     * @return object Populated input object
     *
     * @throws \Switon\Binding\Exception\InputBindingNestingDepthExceededException
     */
    protected function populate(string $type, array $data, Validation $validation, string $path, int $depth): object
    {
        if ($depth >= self::MAX_NESTING_DEPTH) {
            InputBindingNestingDepthExceededException::raise(
                'Input nesting depth exceeds maximum ({depth} levels)',
                ['depth' => $depth]
            );
        }

        $rClass = new ReflectionClass($type);
        $input = $this->maker->make($type);
        $validation->sourceClass = $type;

        foreach ($rClass->getProperties(ReflectionProperty::IS_PUBLIC) as $rProperty) {
            $field = $rProperty->getName();
            $fullPath = $path ? "$path.$field" : $field;

            if ($rProperty->getAttributes(Autowired::class) !== []) {
                continue;
            }

            $validation->sourceClass = $type;
            $validation->field = $fullPath;
            $validation->value = $data[$field] ?? null;

            $rType = $rProperty->getType();
            $validation->targetType = $rType?->getName();
            $hasDefaultValue = $rProperty->hasDefaultValue();
            $isNullable = $rType === null || $rType->allowsNull();
            $hasExplicitRequired = $rProperty->getAttributes(Required::class) !== [];

            $fieldExists = array_key_exists($field, $data);
            $shouldUseDefault = $fieldExists && $hasDefaultValue && ($validation->value === null || $validation->value === '');

            if ($this->isArrayOf($rProperty)) {
                $arrayData = $data[$field] ?? null;

                if ($fieldExists) {
                    if (is_array($arrayData)) {
                        if ($hasDefaultValue && $arrayData === []) {
                            $defaultValue = $rProperty->getDefaultValue();
                            if ($defaultValue === []) {
                                $input->$field = $defaultValue;
                                continue;
                            }
                        }

                        $this->validateArrayCount($rProperty, $arrayData, $validation, $fullPath);
                        $input->$field = $this->populateArray($rProperty, $arrayData, $validation, $fullPath, $depth);
                    } else {
                        $validation->field = $fullPath;
                        $validation->value = $arrayData;
                        $validation->validate(new Type('array'));
                    }
                } else {
                    if ($hasDefaultValue) {
                        $input->$field = $rProperty->getDefaultValue();
                    } elseif ($isNullable) {
                        $input->$field = null;
                    } else {
                        $this->validateRequired($validation, $rProperty);
                    }
                }

                continue;
            }

            if ($rType && !$rType->isBuiltin() && $this->shouldPopulateNested($rType->getName())) {
                $nestedData = $data[$field] ?? null;

                if (is_array($nestedData) && $nestedData !== []) {
                    $input->$field = $this->populate($rType->getName(), $nestedData, $validation, $fullPath, $depth + 1);
                } elseif ($nestedData === null && $isNullable) {
                    $input->$field = null;
                } elseif (!$fieldExists) {
                    if ($hasDefaultValue) {
                        $input->$field = $rProperty->getDefaultValue();
                    } elseif ($isNullable) {
                        $input->$field = null;
                    } else {
                        $this->validateRequired($validation, $rProperty);
                    }
                } elseif ($nestedData === []) {
                    if ($hasDefaultValue) {
                        $input->$field = $rProperty->getDefaultValue();
                    } elseif ($isNullable) {
                        $input->$field = null;
                    } else {
                        $this->validateRequired($validation, $rProperty);
                    }
                } else {
                    $validation->field = $fullPath;
                    $validation->value = $nestedData;
                    $validation->validate(new Type('array'));
                }

                continue;
            }

            if ($fieldExists && !$shouldUseDefault) {
                $validation->value = $this->normalizePropertyInput($rProperty, $validation->value);

                if ($validation->value === null) {
                    if ($isNullable) {
                        if ($hasExplicitRequired) {
                            $validation->validate($rProperty->getAttributes(Required::class)[0]->newInstance());
                        }
                        $input->$field = null;
                        continue;
                    }

                    $this->validateRequired($validation, $rProperty);
                    continue;
                }

                if ($this->isDateProperty($rProperty)) {
                    $dateAttribute = $rProperty->getAttributes(Date::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
                    $validation->validate(
                        $dateAttribute ? $this->maker->make($dateAttribute->getName(), $dateAttribute->getArguments()) : new Date()
                    );
                } else {
                    $validation->validate(new Type($validation->targetType));
                }

                foreach ($rProperty->getAttributes(ConstraintInterface::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                    /** @var ConstraintInterface $constraint */
                    $constraint = $this->maker->make($attribute->getName(), $attribute->getArguments());
                    if ($constraint instanceof Required || $constraint instanceof Type || $constraint instanceof Date) {
                        continue;
                    }
                    $validation->validate($constraint);
                }

                $input->$field = $validation->value;
                continue;
            }

            if ($hasDefaultValue) {
                $input->$field = $rProperty->getDefaultValue();
            } elseif ($isNullable) {
                $input->$field = null;
            } else {
                $this->validateRequired($validation, $rProperty);
            }
        }

        return $input;
    }

    protected function isDateProperty(ReflectionProperty $property): bool
    {
        return $property->getAttributes(Date::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
    }

    protected function shouldPopulateNested(string $type): bool
    {
        if (!class_exists($type)) {
            return false;
        }

        return !(new ReflectionClass($type))->isInternal();
    }

    protected function isArrayOf(ReflectionProperty $property): bool
    {
        return $property->getAttributes(ArrayOf::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
    }

    protected function getArrayOfAttribute(ReflectionProperty $property): ?ArrayOf
    {
        $attribute = $property->getAttributes(ArrayOf::class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null;
        if ($attribute === null) {
            return null;
        }

        /** @var ArrayOf $instance */
        $instance = $this->maker->make($attribute->getName(), $attribute->getArguments());
        return $instance;
    }

    /**
     * @return string|null
     */
    protected function getArrayItemType(ReflectionProperty $property): ?string
    {
        return $this->getArrayOfAttribute($property)?->type;
    }

    /**
     * @param array<array-key, mixed> $data
     */
    protected function validateArrayCount(ReflectionProperty $property, array $data, Validation $validation, string $path): void
    {
        $attribute = $this->getArrayOfAttribute($property);
        if ($attribute === null) {
            return;
        }

        $count = count($data);
        if ($attribute->minItems !== null && $count < $attribute->minItems) {
            $validation->field = $path;
            $validation->value = $data;
            $validation->addError(
                'The {field} must contain at least {minItems} items.',
                ['minItems' => $attribute->minItems]
            );
            return;
        }

        if ($attribute->maxItems !== null && $count > $attribute->maxItems) {
            $validation->field = $path;
            $validation->value = $data;
            $validation->addError(
                'The {field} must contain at most {maxItems} items.',
                ['maxItems' => $attribute->maxItems]
            );
        }
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    protected function populateArray(ReflectionProperty $property, array $data, Validation $validation, string $path, int $depth): array
    {
        $itemType = $this->getArrayItemType($property);
        if ($itemType === null) {
            return $data;
        }

        $result = [];

        foreach ($data as $index => $value) {
            $itemPath = $path . '.' . $index;

            if ($value === null) {
                $result[$index] = null;
                continue;
            }

            if (class_exists($itemType)) {
                if (!is_array($value)) {
                    $validation->field = $itemPath;
                    $validation->value = $value;
                    $validation->targetType = 'array';
                    $validation->validate(new Type('array'));
                    $result[$index] = $value;
                    continue;
                }

                $result[$index] = $this->populate($itemType, $value, $validation, $itemPath, $depth + 1);
                continue;
            }

            $validation->field = $itemPath;
            $validation->value = $value;
            $validation->targetType = $itemType;
            $validation->validate(new Type($itemType));
            $result[$index] = $validation->value;
        }

        return $result;
    }
}
