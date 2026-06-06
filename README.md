# Switon Binding Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/binding/ci.yml?branch=main&label=CI)](https://github.com/switon-php/binding/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's binding layer for `#[ResolvedBy]` classes, action arguments, and typed input objects.

## Highlights

- **Declared resolution:** `#[ResolvedBy]` lets a class declare its resolver.
- **Action argument binding:** parameters can come from events, resolvers, the container, or plain input.
- **Typed input binding:** arrays can become typed objects with validation and nested data support.
- **Separate paths:** scalar values and objects use separate binding flows.
- **Resolution events:** argument resolution emits its own lifecycle events.

## Installation

```bash
composer require switon/binding
```

## Quick Start

Binding starts with `PageResolver`.

```php
use Switon\Binding\Attribute\ResolvedBy;
use Switon\Orm\PageResolver;

#[ResolvedBy(PageResolver::class)]
class Page
{
    protected int $page;
    protected int $limit;

    public static function of(int $page, int $limit = 10): static
    {
        $instance = new static();
        $instance->page = max(1, $page);
        $instance->limit = max(1, $limit);

        return $instance;
    }
}
```

```php
use ReflectionParameter;
use Switon\Core\Attribute\Autowired;
use Switon\Binding\ValueResolverInterface;
use Switon\Core\InputInterface;
use Switon\Orm\Page;

final class PageResolver implements ValueResolverInterface
{
    #[Autowired] protected InputInterface $input;

    public function resolve(ReflectionParameter $parameter, string $type): mixed
    {
        return Page::of(
            (int) $this->input->get('page', 1),
            (int) $this->input->get('size', 10)
        );
    }
}
```

```php
use Switon\Orm\Page;

final class ArticleController
{
    public function indexAction(Page $page): void
    {
    }
}
```

Docs: https://docs.switon.dev/latest/binding

## License

MIT.
