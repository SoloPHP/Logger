---
layout: home

hero:
  name: Solo Logger
  text: PSR-3 Logger for PHP
  tagline: Coordinated file rotation, standard error for containers, bounded context, and one line per record in line, JSON or logfmt format.
  image:
    src: /logo.svg
    alt: Solo Logger
  actions:
    - theme: brand
      text: Get Started
      link: /guide/installation
    - theme: alt
      text: View on GitHub
      link: https://github.com/SoloPHP/Logger

features:
  - icon: 📜
    title: PSR-3 and PSR-20
    details: The eight standard levels, a minimum level, channels, and any PSR-20 clock for deterministic timestamps.
  - icon: 🔄
    title: Files and Streams
    details: Rotation by size, interval or calendar, with retention, safe for many processes appending to one file. Standard error for containers.
  - icon: 🧾
    title: Three Formats
    details: Line, NDJSON and logfmt. Every record is one physical line with a fixed shape.
  - icon: 🛡️
    title: Bounded Context
    details: Exceptions, dates, enums and Stringables are converted safely. Depth, entries and string length are capped, and the keys you name are redacted.
  - icon: 🔒
    title: Safe Output
    details: Control characters are escaped, invalid UTF-8 is replaced, and symlinked log files are refused.
  - icon: 🧩
    title: Composable
    details: Decorators send records to several destinations, keep output failures away from requests and add shared context.
---

<style>
/* Set on the hero, not :root: the theme's own :root values come later in the built CSS and would win. */
.VPHero {
  --vp-home-hero-name-color: transparent;
  --vp-home-hero-name-background: linear-gradient(135deg, #14b8a6 0%, #84cc16 100%);
  --vp-home-hero-image-background-image: linear-gradient(135deg, #14b8a630 0%, #84cc1630 100%);
  --vp-home-hero-image-filter: blur(44px);
}

.VPHero .VPImage {
  max-width: 200px;
  max-height: 200px;
}
</style>

## Quick Example

```php
use Solo\Logger\Logger;

$logger = Logger::file('/var/log/app/app.log');

$logger->info('User {id} signed in', ['id' => 42]);
// [2026-11-01T09:30:00.123456+00:00] INFO: User 42 signed in {"id":42}

$logger->error('Payment failed', ['exception' => $exception, 'order_id' => 1001]);
// The exception is logged with its class, message, location, stack frames and previous exceptions.
```

## Installation

```bash
composer require solophp/logger
```

**Requirements:** PHP 8.3+
