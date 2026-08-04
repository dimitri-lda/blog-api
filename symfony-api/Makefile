.PHONY: init up down test e2e build migrate fixtures logs

init:
	@test -f .env.local || cp .env.example .env.local
	docker compose build php
	docker compose run --rm php sh -c 'mkdir -p config/jwt && test -f config/jwt/private.pem || openssl genpkey -algorithm RSA -out config/jwt/private.pem -pkeyopt rsa_keygen_bits:4096 -aes-256-cbc -pass pass:change-me && test -f config/jwt/public.pem || openssl pkey -pubout -in config/jwt/private.pem -out config/jwt/public.pem -passin pass:change-me'
	docker compose run --rm php composer install
	docker compose run --rm node npm install
	docker compose up -d postgres redis mailpit
	docker compose run --rm php php bin/console doctrine:migrations:migrate --no-interaction
	docker compose run --rm php php bin/console doctrine:fixtures:load --no-interaction

up:
	docker compose up --build

down:
	docker compose down

migrate:
	docker compose run --rm php php bin/console doctrine:migrations:migrate --no-interaction

fixtures:
	docker compose run --rm php php bin/console doctrine:fixtures:load --no-interaction

test:
	docker compose run --rm -e APP_ENV=test php php bin/console doctrine:database:create --if-not-exists
	docker compose run --rm -e APP_ENV=test php php bin/console doctrine:migrations:migrate --no-interaction
	docker compose run --rm -e APP_ENV=test php php bin/console doctrine:fixtures:load --no-interaction
	docker compose run --rm -e APP_ENV=test php php bin/phpunit
	docker compose run --rm node npm test -- --run
	docker compose run --rm node npm run typecheck

e2e:
	docker compose --profile test run --rm playwright

build:
	docker compose run --rm node npm run build
	docker build --target prod -t sportivo-symfony:latest .

logs:
	docker compose logs -f
