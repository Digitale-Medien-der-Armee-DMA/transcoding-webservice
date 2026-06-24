COMPOSE ?= docker compose
COMPOSE_FILE ?= compose.yaml

.PHONY: build up down logs ps pull restart shell worker-shell migrate test framework-stage1-check compose-validate ffmpeg-cpu-smoke ffmpeg-gpu-smoke dev-up dev-down dev-logs

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
	vendor/bin/phpunit tests/Feature/VimpContractTest.php tests/Feature/HealthMetricsTest.php tests/Feature/WorkerGuardrailsTest.php tests/Feature/StatusSchemaTest.php tests/Feature/SecurityHardeningTest.php

framework-stage1-check:
	php scripts/upgrade/framework_stage1_check.php

compose-validate:
	$(COMPOSE) --env-file .env.example -f compose.yaml config >/dev/null
	$(COMPOSE) --profile smoke --profile gpu-smoke --env-file .env.example -f compose.yaml config >/dev/null
	$(COMPOSE) --env-file .env.example -f compose.yaml -f compose.dev.yaml config >/dev/null

ffmpeg-cpu-smoke:
	$(COMPOSE) --profile smoke -f $(COMPOSE_FILE) build ffmpeg-smoke-cpu
	$(COMPOSE) --profile smoke -f $(COMPOSE_FILE) run --rm ffmpeg-smoke-cpu

ffmpeg-gpu-smoke:
	$(COMPOSE) --profile gpu-smoke -f $(COMPOSE_FILE) build ffmpeg-smoke-gpu
	$(COMPOSE) --profile gpu-smoke -f $(COMPOSE_FILE) run --rm ffmpeg-smoke-gpu

dev-up:
	$(COMPOSE) -f compose.yaml -f compose.dev.yaml up -d --build

dev-down:
	$(COMPOSE) -f compose.yaml -f compose.dev.yaml down

dev-logs:
	$(COMPOSE) -f compose.yaml -f compose.dev.yaml logs -f --tail=200
