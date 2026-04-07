---
name: Bug report
about: Something is broken or behaving unexpectedly
title: '[Bug] '
labels: bug
assignees: ''
---

## Describe the bug

<!-- A clear and concise description of what the bug is. -->

## PHP version and package version

```
PHP: (output of `php --version`)
Package: (e.g. 1.0.2)
```

## Reproducing code

```php
<?php
require 'vendor/autoload.php';

use Astm\Astm;

// Minimal code that triggers the bug
$message = Astm::parse('...');
```

## Raw ASTM message (if applicable)

```
H|\^&|||INSTRUMENT||||||||E1394-97
...
L|1|N
```

> Please anonymise any patient data before pasting.

## Expected behaviour

<!-- What you expected to happen. -->

## Actual behaviour

<!-- What actually happened. Include any exception message and stack trace. -->

## Additional context

<!-- Any other context (instrument model, transport type, etc.). -->
