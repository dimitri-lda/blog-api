# Sportivo — Symfony API + React

Independent learning implementation of the Sportivo store. It does not share code, database volumes or migrations with the Laravel application in the parent directory.

## Stack

- PHP 8.3 and Symfony 7.4 LTS
- Doctrine ORM with PostgreSQL 16
- JWT access tokens and rotating opaque refresh tokens
- Redis cache, rate limiting and Messenger transport
- Mailpit for development email
- React 19, TypeScript, Vite, TanStack Query, React Hook Form and Tailwind CSS
- PHPUnit, Vitest and Playwright

## Start from a clean checkout

All application commands run in Docker. Local PHP, Composer, Node and PostgreSQL are not used.

```bash
cd symfony-api
make init
make up
```

Open:

- Store: http://localhost:8080
- Vite directly: http://localhost:5174
- Mailpit: http://localhost:8026

Fixture accounts all use the password `password`:

- `test@example.com`
- `admin@example.com`
- `superadmin@example.com`

The admin UI is available at http://localhost:8080/admin/orders.

## Commands

```bash
make up          # start the development stack
make down        # stop it without deleting data
make migrate     # apply Doctrine migrations
make fixtures    # reset and seed development data
make test        # backend, frontend and type checks
make build       # frontend and production container builds
make logs        # follow service logs
```

Environment defaults are documented in `.env.example`. Use `.env.local` for local overrides and keep production secrets outside the repository. Set `COOKIE_SECURE=true` behind HTTPS.

## API conventions

Successful resources use `{ "data": ... }`; collections with pagination add `meta`. Validation failures use `{ "message": "...", "errors": { "field": ["..."] } }`.

Main endpoint groups:

- `/api/home`, `/api/categories`, `/api/products`
- `/api/cart`, `/api/orders`, `/api/me/orders`
- `/api/auth/*`, `/api/profile`
- `/api/admin/orders/*`

The access JWT is kept only in React memory. The rotating refresh token is stored in an HttpOnly SameSite cookie and only its SHA-256 hash is persisted. Guest cart and guest order access use separate HttpOnly cookies.
