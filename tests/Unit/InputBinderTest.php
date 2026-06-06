<?php

declare(strict_types=1);

namespace Switon\Binding\Tests\Unit;

use ReflectionProperty;
use Switon\Binding\Exception\InputBindingNestingDepthExceededException;
use Switon\Binding\InputBinder;
use Switon\Binding\Tests\TestCase;
use Switon\Binding\Tests\Unit\Fixtures\BindingDateInputFixture;
use Switon\Binding\Tests\Unit\Fixtures\BindingDateIntInputFixture;
use Switon\Binding\Tests\Unit\Fixtures\BindingInputFixtures;
use Switon\Binding\Tests\Unit\Fixtures\BindingNestedInput;
use Switon\Binding\Tests\Unit\Fixtures\BindingAutowiredInputFixture;
use Switon\Binding\Tests\Unit\Fixtures\BindingNotEmptyCodeFixture;
use Switon\Binding\Tests\Unit\Fixtures\BindingNullNormalizedFixture;
use Switon\Binding\Tests\Unit\Fixtures\BindingRequiredListFixture;
use Switon\Binding\Tests\Unit\Fixtures\BindingSlugRequiredFixture;
use Switon\Binding\Tests\Unit\Fixtures\BindingTagsInputFixture;
use Switon\Binding\Tests\Unit\Fixtures\BindingUpperTitleFixture;
use Switon\Core\MakerInterface;
use Switon\Validating\Attribute\ArrayOf;
use Switon\Validating\Exception\ValidateFailedException;
use Switon\Validating\ValidatorInterface;
use DateTime;

require_once __DIR__ . '/Fixtures/BindingInputFixtures.php';

final class InputBinderTest extends TestCase
{
    protected function createBinder(ValidatorInterface $validator): InputBinder
    {
        $maker = $this->container->get(MakerInterface::class);

        return new class ($validator, $maker) extends InputBinder {
            public function __construct(
                ValidatorInterface $validator,
                MakerInterface $maker
            ) {
                $this->validator = $validator;
                $this->maker = $maker;
            }
        };
    }

    public function testBindTreatsNullAsMissingAndKeepsDefaultValue(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validation = new \Switon\Validating\Validation($validator, ['child' => null]);
        $validator->expects($this->once())->method('beginValidate')->willReturn($validation);
        $validator->expects($this->once())->method('endValidate');

        $binder = $this->createBinder($validator);

        $input = $binder->bind(BindingInputFixtures::class, ['child' => null]);

        self::assertSame('default-child', $input->child);
    }

    public function testBindTreatsNestedNullAsMissingAndKeepsDefaultValue(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validation = new \Switon\Validating\Validation($validator, ['nested' => null]);
        $validator->expects($this->once())->method('beginValidate')->willReturn($validation);
        $validator->expects($this->once())->method('endValidate');

        $binder = $this->createBinder($validator);

        $input = $binder->bind(BindingInputFixtures::class, ['nested' => null]);

        self::assertSame('default-nested', $input->nested->value);
    }

    public function testBindSkipsAutowiredPropertiesAndKeepsInjectedValue(): void
    {
        $binder = $this->container->make(InputBinder::class);
        $validator = $this->container->get(ValidatorInterface::class);

        $input = $binder->bind(BindingAutowiredInputFixture::class, [
            'validator' => 'override-me',
            'value' => 'hello',
        ]);

        self::assertSame('hello', $input->value);
        self::assertSame($validator, $input->validator);
    }

    public function testBindThrowsExceptionForExcessiveNestedInputDepth(): void
    {
        $validator = $this->createMock(ValidatorInterface::class);
        $validation = new \Switon\Validating\Validation($validator, []);
        $validator->expects($this->once())->method('beginValidate')->willReturn($validation);
        $validator->expects($this->never())->method('endValidate');

        $binder = $this->createBinder($validator);

        $source = [];
        $cursor = &$source;
        for ($i = 0; $i < 11; $i++) {
            $cursor['child'] = [];
            $cursor = &$cursor['child'];
        }

        $this->expectException(InputBindingNestingDepthExceededException::class);
        $this->expectExceptionMessage('Input nesting depth exceeds maximum');

        $binder->bind(BindingRecursiveInputFixture::class, $source);
    }

    public function testBindThrowsWhenArrayOfMinItemsNotSatisfied(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);

        $binder = $this->createBinder($validator);

        $this->expectException(ValidateFailedException::class);

        $binder->bind(BindingTagsInputFixture::class, ['tags' => ['only-one']]);
    }

    public function testBindUsesMakerToInstantiateArrayOfMetadataWhenSet(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);

        $maker = $this->createMock(MakerInterface::class);
        $maker->expects($this->atLeastOnce())
            ->method('make')
            ->willReturnCallback(static function (string $name, array $parameters = []): mixed {
                if ($name === ArrayOf::class) {
                    return new ArrayOf(type: 'string', minItems: 2);
                }
                if ($name === BindingTagsInputFixture::class) {
                    return new BindingTagsInputFixture();
                }

                self::fail('Unexpected make: ' . $name);
            });

        $binder = new class ($validator, $maker) extends InputBinder {
            public function __construct(ValidatorInterface $validator, MakerInterface $maker)
            {
                $this->validator = $validator;
                $this->maker = $maker;
            }
        };

        $input = $binder->bind(BindingTagsInputFixture::class, ['tags' => ['a', 'b']]);

        self::assertSame(['a', 'b'], $input->tags);
    }

    public function testBindPopulatesNestedArrayObjectsAndKeepsElementShape(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $input = $binder->bind(BindingInputFixtures::class, [
            'nested' => ['value' => 'nested'],
            'nestedList' => [
                ['value' => 'first'],
                ['value' => 'second'],
            ],
        ]);

        self::assertCount(2, $input->nestedList);
        self::assertSame('first', $input->nestedList[0]->value);
        self::assertSame('second', $input->nestedList[1]->value);
    }

    public function testBindThrowsWhenArrayOfExceedsMaxItems(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $this->expectException(ValidateFailedException::class);
        $binder->bind(BindingInputFixtures::class, [
            'nestedList' => [
                ['value' => 'first'],
                ['value' => 'second'],
                ['value' => 'third'],
                ['value' => 'fourth'],
            ],
        ]);
    }

    public function testBindKeepsEmptyArrayDefaultForArrayOfProperty(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $input = $binder->bind(BindingInputFixtures::class, [
            'nested' => ['value' => 'nested'],
            'labels' => [],
        ]);

        self::assertSame([], $input->labels);
    }

    public function testBindPopulatesStringArrayItemsAndPreservesNullEntries(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $input = $binder->bind(BindingInputFixtures::class, [
            'nested' => ['value' => 'nested'],
            'labels' => [null, 'alpha'],
        ]);

        self::assertSame([null, 'alpha'], $input->labels);
    }

    public function testBindRejectsNonArrayValueForArrayOfProperty(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $this->expectException(ValidateFailedException::class);
        $binder->bind(BindingInputFixtures::class, [
            'nested' => ['value' => 'nested'],
            'labels' => 'oops',
        ]);
    }

    public function testBindRejectsNonArrayNestedItemForArrayOfProperty(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $this->expectException(ValidateFailedException::class);
        $binder->bind(BindingInputFixtures::class, [
            'nested' => ['value' => 'nested'],
            'looseNestedList' => ['oops'],
        ]);
    }

    public function testBindRejectsScalarForNestedObjectProperty(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $this->expectException(ValidateFailedException::class);
        $binder->bind(BindingInputFixtures::class, [
            'nested' => 'not-an-array',
        ]);
    }

    public function testBindSetsNullableNestedPeerToNullWhenExplicitNullProvided(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $input = $binder->bind(BindingInputFixtures::class, [
            'nested' => ['value' => 'nested'],
            'peer' => null,
        ]);

        self::assertNull($input->peer);
    }

    public function testBindAppliesPropertyNormalizerAttributeBeforeValidation(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $input = $binder->bind(BindingUpperTitleFixture::class, [
            'nested' => ['value' => 'nested'],
            'title' => 'hello',
        ]);

        self::assertSame('HELLO', $input->title);
    }

    public function testBindThrowsWhenRequiredArrayOfFieldIsMissing(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $this->expectException(ValidateFailedException::class);
        $binder->bind(BindingRequiredListFixture::class, []);
    }

    public function testBindThrowsWhenNotEmptyConstraintFailsAfterType(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $this->expectException(ValidateFailedException::class);
        $binder->bind(BindingNotEmptyCodeFixture::class, [
            'code' => '',
        ]);
    }

    public function testBindPopulatesArrayOfIntItems(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $input = $binder->bind(BindingInputFixtures::class, [
            'nested' => ['value' => 'nested'],
            'scores' => [1, 2, 3],
        ]);

        self::assertSame([1, 2, 3], $input->scores);
    }

    public function testBindKeepsNullForMissingNullableArrayOfProperty(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $input = $binder->bind(BindingInputFixtures::class, [
            'nested' => ['value' => 'nested'],
        ]);

        self::assertNull($input->optionalList);
    }

    public function testBindThrowsWhenRequiredSlugFieldMissing(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $this->expectException(ValidateFailedException::class);
        $binder->bind(BindingSlugRequiredFixture::class, [
            'nested' => ['value' => 'nested'],
        ]);
    }

    public function testBindThrowsWhenNormalizerClearsNonNullableScalar(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $this->expectException(ValidateFailedException::class);
        $binder->bind(BindingNullNormalizedFixture::class, [
            'nested' => ['value' => 'nested'],
            'code' => 'any',
        ]);
    }

    public function testBindThrowsForExplicitRequiredNullableFieldWhenNullIsProvided(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $this->expectException(ValidateFailedException::class);
        $binder->bind(BindingInputFixtures::class, [
            'nested' => ['value' => 'nested'],
            'requiredNullableLabel' => null,
        ]);
    }

    public function testProtectedHelpersHandleMissingAndInternalTypes(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $maker = $this->container->get(MakerInterface::class);

        $binder = new class ($validator, $maker) extends InputBinder {
            public function __construct(
                ValidatorInterface $validator,
                MakerInterface $maker
            ) {
                $this->validator = $validator;
                $this->maker = $maker;
            }

            public function shouldPopulate(string $type): bool
            {
                return $this->shouldPopulateNested($type);
            }

            public function arrayOf(ReflectionProperty $property): ?ArrayOf
            {
                return $this->getArrayOfAttribute($property);
            }

            public function itemType(ReflectionProperty $property): ?string
            {
                return $this->getArrayItemType($property);
            }

            public function populateRaw(ReflectionProperty $property, array $data): array
            {
                $validation = new \Switon\Validating\Validation($this->validator, []);

                return $this->populateArray($property, $data, $validation, 'items', 0);
            }

            public function validateCount(ReflectionProperty $property, array $data): void
            {
                $validation = new \Switon\Validating\Validation($this->validator, []);
                $this->validateArrayCount($property, $data, $validation, 'items');
            }
        };

        $childProperty = new ReflectionProperty(BindingInputFixtures::class, 'child');
        $labelsProperty = new ReflectionProperty(BindingInputFixtures::class, 'labels');

        self::assertTrue($binder->shouldPopulate(BindingNestedInput::class));
        self::assertFalse($binder->shouldPopulate(DateTime::class));
        self::assertFalse($binder->shouldPopulate('Switon\\Binding\\Tests\\Unit\\Fixtures\\MissingInputBinderClass'));
        self::assertNull($binder->arrayOf($childProperty));
        self::assertNull($binder->itemType($childProperty));
        self::assertSame('string', $binder->itemType($labelsProperty));
        $scoresProperty = new ReflectionProperty(BindingInputFixtures::class, 'scores');
        self::assertSame('int', $binder->itemType($scoresProperty));
        self::assertSame(['raw'], $binder->populateRaw($childProperty, ['raw']));
        $binder->validateCount($childProperty, ['raw']);
    }

    public function testBindNormalizesDateStringToTimestampForIntProperty(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $input = $binder->bind(BindingDateIntInputFixture::class, [
            'createdAt' => '2024-01-02 03:04:05',
        ]);

        self::assertSame(strtotime('2024-01-02 03:04:05'), $input->createdAt);
    }

    public function testBindNormalizesDateStringToFormattedStringForStringProperty(): void
    {
        $validator = $this->container->get(ValidatorInterface::class);
        $binder = $this->createBinder($validator);

        $input = $binder->bind(BindingDateInputFixture::class, [
            'createdAt' => 1704067200,
        ]);

        self::assertSame('2024-01-01 00:00:00', $input->createdAt);
    }
}

final class BindingRecursiveInputFixture
{
    public ?BindingRecursiveInputFixture $child = null;
}
