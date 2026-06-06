<?php

declare(strict_types=1);

namespace Switon\Binding\Tests\Unit\Attribute;

use Switon\Binding\Attribute\ResolvedBy;
use Switon\Binding\Tests\TestCase;

final class ResolvedByTest extends TestCase
{
    public function testAttributeStoresResolverFqcn(): void
    {
        $attr = new ResolvedBy('Switon\\Testing\\ExampleParameterResolver');

        self::assertSame('Switon\\Testing\\ExampleParameterResolver', $attr->getResolver());
    }
}
