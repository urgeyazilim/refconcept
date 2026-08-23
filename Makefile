# RefConcept developer commands (Linux/macOS/CI).
# Windows: use scripts\rc.ps1 with the same verbs.

COMPOSE := docker compose
API     := $(COMPOSE) exec -T api

.DEFAULT_GOAL := help
.PHONY: help up down restart logs shell status sync pull watch migrate fresh seed test analyse bootstrap web dev build-images

help: ## show available commands
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

up: ## start the docker stack and sync the API source into it
	$(COMPOSE) up -d
	bash scripts/sync.sh

sync: ## push apps/api into the api container (required after edits)
	bash scripts/sync.sh

pull: ## copy container-generated files back to apps/api
	bash scripts/sync.sh --pull

watch: ## continuous host -> container sync
	$(COMPOSE) watch

down: ## stop the docker stack
	$(COMPOSE) down

restart: ## restart the docker stack
	$(COMPOSE) restart

logs: ## follow logs
	$(COMPOSE) logs -f

shell: ## shell into the api container
	$(COMPOSE) exec api bash

status: ## service state and health probe
	$(COMPOSE) ps
	@curl -fsS http://localhost:$${API_PORT_HOST:-58000}/api/health || echo "health endpoint unreachable"

migrate: ## run migrations
	$(API) php artisan migrate

fresh: ## rebuild the database with seeds
	$(API) php artisan migrate:fresh --seed

seed: ## run seeders
	$(API) php artisan db:seed

test: ## backend tests
	$(API) php artisan test

analyse: ## static analysis
	$(API) ./vendor/bin/phpstan analyse --memory-limit=1G

bootstrap: ## first-time setup
	bash scripts/bootstrap.sh

web: ## install frontend dependencies
	npm install

dev: ## start storefront dev server
	npm run dev:storefront

build-images: ## build every container image (parity with CI)
	$(COMPOSE) build
