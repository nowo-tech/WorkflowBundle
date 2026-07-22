# Demo application with FrankenPHP (development and production)

This document describes how the **Workflow Bundle** demo (`demo/symfony8`) runs under **FrankenPHP** in Docker, and how to reproduce **development** (no cache, changes visible on refresh) and **production** (worker mode, cache enabled) configurations.

## Contents

- [Overview](#overview)
- [What the demo includes](#what-the-demo-includes)
- [Development configuration](#development-configuration)
- [Production configuration](#production-configuration)
- [Troubleshooting](#troubleshooting)

---

## Overview

**The `demo/` folder is not shipped when the bundle is installed** (excluded via `archive.exclude` in `composer.json`). To run the demo, clone this repository.

The demo uses:

- **FrankenPHP** (Caddy + PHP) in a single PHP container plus **PostgreSQL** on the Docker network (no host port exposed).
- **Docker Compose** with the app and parent bundle mounted (`../..` → `/var/workflow-bundle`).
- **Two Caddyfiles**: production (`docker/frankenphp/Caddyfile` with worker) and development (`docker/frankenphp/Caddyfile.dev`, no worker).
- An **entrypoint** that selects classic vs worker Caddyfile from **`FRANKENPHP_MODE`** (`classic` \| `worker`, default **`worker`** in `.env.example`)

| Aspect | Development | Production |
|--------|-------------|------------|
| `FRANKENPHP_MODE` | Prefer **`classic`** (hot-reload) | **`worker`** (default in `.env.example`) |
| FrankenPHP worker mode | **Off** (`classic`) | **On** (`worker`) |
| Twig cache | **Off** (`config/packages/dev/twig.yaml`) | **On** (default) |
| OPcache revalidation | Every request (`docker/php-dev.ini`) | Default |
| `APP_ENV` / `APP_DEBUG` | `dev` / `1` | `prod` / `0` |

**Port:** default `8022` (`PORT` in `demo/symfony8/.env.example`).

Start from the bundle root:

```bash
make -C demo up-symfony8
# or
make -C demo/symfony8 up
```

Open `http://localhost:8022` — CRUD UI at `/workflow`, demo playgrounds for orders and documents.

---

## What the demo includes

- **Symfony Web Profiler** and **Debug bundle** in `dev` / `test`.
- **Nowo Twig Inspector** in `dev` / `test`.
- **Workflow Bundle** with Doctrine + PostgreSQL for persisted workflow definitions.

Example `config/bundles.php`:

```php
<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\TwigInspectorBundle\NowoTwigInspectorBundle;
use Nowo\WorkflowBundle\NowoWorkflowBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;

return [
    FrameworkBundle::class         => ['all' => true],
    TwigBundle::class              => ['all' => true],
    WebProfilerBundle::class       => ['dev' => true, 'test' => true],
    DoctrineBundle::class          => ['all' => true],
    NowoWorkflowBundle::class      => ['all' => true],
    NowoTwigInspectorBundle::class => ['dev' => true, 'test' => true],
];
```

---

## Development configuration

- **`FRANKENPHP_MODE=classic`** — entrypoint copies `Caddyfile.dev` (plain `php_server`, no worker).
- **php-dev.ini** — `opcache.revalidate_freq=0`.
- **docker-compose.yml** — `dns:` for Packagist, bundle path repo, `APP_ENV=dev`, passes `FRANKENPHP_MODE`.

After changing `FRANKENPHP_MODE` in `.env`, recreate with `docker compose up -d`.

See also [IconSelectorBundle DEMO-FRANKENPHP.md](https://github.com/nowo-tech/IconSelectorBundle/blob/main/docs/DEMO-FRANKENPHP.md) for the full FrankenPHP pattern used across Nowo bundles.

---

## Production configuration

Set **`FRANKENPHP_MODE=worker`** (default), `APP_ENV=prod` and `APP_DEBUG=0`, and do not mount `php-dev.ini`. Warm up Symfony cache after deploy.

---

## Troubleshooting

| Problem | Check |
|---------|--------|
| Composer cannot reach Packagist | Ensure `dns: 8.8.8.8 / 8.8.4.4` is set on the PHP service (REQ-DEMO-009). |
| Twig / PHP changes not visible | Set `FRANKENPHP_MODE=classic`, confirm `APP_ENV=dev`, and `twig.cache: false` in dev config; recreate the container after changing mode. |
| Database connection errors | Use `database` as hostname inside Docker (not `localhost`). |
