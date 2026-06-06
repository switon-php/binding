<?php

declare(strict_types=1);

namespace Switon\Binding\Tests\Unit\Fixtures;

use Attribute;
use ReflectionProperty;
use Switon\Core\Attribute\Autowired;
use Switon\Validating\Attribute\ArrayOf;
use Switon\Validating\Attribute\Date;
use Switon\Validating\Attribute\NotEmpty;
use Switon\Validating\Attribute\Required;
use Switon\Validating\Attribute\Type;

final class BindingInputFixtures
{
    public string $child = 'default-child';

    public BindingNestedInput $nested;

    public ?BindingNestedInput $peer = null;

    #[ArrayOf(type: BindingNestedInput::class, minItems: 2, maxItems: 3)]
    public array $nestedList = [];

    #[ArrayOf(type: 'string', minItems: 1)]
    public array $labels = [];

    #[ArrayOf(type: BindingNestedInput::class)]
    public array $looseNestedList = [];

    #[ArrayOf(type: 'int')]
    public array $scores = [];

    #[ArrayOf(type: 'string')]
    public ?array $optionalList = null;

    #[Required]
    public ?string $requiredNullableLabel;

    public function __construct()
    {
        $this->nested = new BindingNestedInput();
    }
}

final class BindingNestedInput
{
    public string $value = 'default-nested';
}

final class BindingTagsInputFixture
{
    /**
     * @var list<string>
     */
    #[ArrayOf(type: 'string', minItems: 2)]
    public array $tags = [];
}

final class BindingDateInputFixture
{
    #[Date]
    public string $createdAt = '';
}

final class BindingDateIntInputFixture
{
    #[Date]
    public int $createdAt = 0;
}

final class BindingNotEmptyCodeFixture
{
    #[Type('string'), NotEmpty]
    public string $code;

    public function __construct()
    {
        $this->code = 'seed';
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class UpperInputFixtureNormalizer
{
    public function normalizeInput(ReflectionProperty $property, mixed $value): mixed
    {
        return is_string($value) ? strtoupper($value) : $value;
    }
}

final class BindingUpperTitleFixture
{
    public BindingNestedInput $nested;

    #[UpperInputFixtureNormalizer]
    public string $title = '';

    public function __construct()
    {
        $this->nested = new BindingNestedInput();
    }
}

final class BindingRequiredListFixture
{
    #[ArrayOf(type: 'string')]
    public array $items;
}

final class BindingSlugRequiredFixture
{
    public BindingNestedInput $nested;

    #[Required]
    public string $slug;

    public function __construct()
    {
        $this->nested = new BindingNestedInput();
    }
}

#[Attribute(Attribute::TARGET_PROPERTY)]
final class NullToNormalizer
{
    public function normalizeInput(ReflectionProperty $property, mixed $value): mixed
    {
        return null;
    }
}

final class BindingNullNormalizedFixture
{
    public BindingNestedInput $nested;

    #[NullToNormalizer]
    public string $code = '';

    public function __construct()
    {
        $this->nested = new BindingNestedInput();
    }
}

final class BindingAutowiredInputFixture
{
    #[Autowired] public \Switon\Validating\ValidatorInterface $validator;

    public string $value = '';
}
