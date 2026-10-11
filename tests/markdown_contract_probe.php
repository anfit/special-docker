<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Markdown.php';

$source = <<<'MARKDOWN'
# A spec

**Important** and [reference](https://example.test/spec)

[unsafe](javascript:alert(1))

| Field | Required |
| :--- | :---: |
| title | yes |
| body | no |

- [x] reviewed
  - nested note

- first item
- second item

> A useful note

```php
<script>alert('xss')</script>
```
MARKDOWN;

echo json_encode([
    'html' => \Special\Markdown::render($source),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
