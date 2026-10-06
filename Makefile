.PHONY: help up down build logs shell psql migrate db-reset

DC = docker compose

help: ## Show this help
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F ':.*## ' '{printf "  %-10s %s\n", $$1, $$2}'

up: ## Build and start the stack (app: http://localhost:8080, mails: http://localhost:1080)
	$(DC) up -d --build

down: ## Stop the stack
	$(DC) down

build: ## Rebuild the app image
	$(DC) build

logs: ## Follow the logs of all services
	$(DC) logs -f

shell: ## Open a shell in the app container
	$(DC) exec -u www-data app bash

psql: ## Open a psql session on the collec database
	$(DC) exec db psql -U collec -d collec

migrate: ## Upgrade the database to the current version (upgradedb.sh)
	$(DC) exec -u www-data app ./upgradedb.sh

db-reset: ## Delete the database and recreate it from install/pgsql/collec_create.sql
	$(DC) down -v
	$(DC) up -d
