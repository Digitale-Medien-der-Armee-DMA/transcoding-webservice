COMPOSE ?= docker compose
COMPOSE_FILE ?= compose.yaml

.PHONY: build up down logs ps pull restart shell worker-shell migrate test dev-up dev-down dev-logs

build:
	$(COMPOSE) -f $(COMPOSE_FILE) build

up:
	$(COMPOSE) -f $(COMPOSE_FILE) up -d

down:
	$(COMPOSE) -f $(COMPOSE_FILE) down

logs:
	$(COMPOSE) -f $(COMPOSE_FILE) logs -f --tail=200

ps:
	$(COMPOSE) -f $(COMPOSE_FILE) ps

pull:
	git pull --ff-only

restart:
	$(COMPOSE) -f $(COMPOSE_FILE) restart

shell:
	$(COMPOSE) -f $(COMPOSE_FILE) exec app sh

worker-shell:
	$(COMPOSE) -f $(COMPOSE_FILE) exec worker-video-gpu sh

migrate:
	$(COMPOSE) -f $(COMPOSE_FILE) exec app php artisan migrate --force

test:
	vendor/bin/phpunit tests/Feature/VimpContractTest.php tests/Feature/HealthMetricsTest.php

dev-up:
	$(COMPOSE) -f compose.yaml -f compose.dev.yaml up -d --build

dev-down:
	$(COMPOSE) -f compose.yaml -f compose.dev.yaml down

dev-logs:
	$(COMPOSE) -f compose.yaml -f compose.dev.yaml logs -f --tail=200
