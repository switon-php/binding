<?php

declare(strict_types=1);

namespace Switon\Binding\Tests;

use Switon\Core\Filesystem;
use Switon\Core\FilesystemInterface;
use Switon\Core\LocaleInterface;
use Switon\Core\PathAliasInterface;
use Switon\Testing\TestCase as BaseTestCase;

/**
 * Base test case for Binding tests.
 */
abstract class TestCase extends BaseTestCase
{
    protected function setUpContainer(): void
    {
        $locale = $this->createMock(LocaleInterface::class);
        $locale->method('get')->willReturn('en');
        $locale->method('set')->willReturnSelf();
        $this->container->set(LocaleInterface::class, $locale);

        $filesystem = $this->createMock(Filesystem::class);
        /** @var PathAliasInterface $pathAlias */
        $pathAlias = $this->container->get(PathAliasInterface::class);
        $templateDir = (string)$pathAlias->get('@switon.validator.resources');
        $filesystem->method('glob')->willReturn([
            $templateDir . '/en.php',
            $templateDir . '/zh-cn.php',
        ]);
        $this->container->set(FilesystemInterface::class, $filesystem);
    }
}
