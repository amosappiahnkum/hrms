# Development reads .env (docker-compose.yml); production reads .env.production (docker-compose.prod.yml), for the
# containers and for the ${…} values in the compose file alike.
ENV_DEV      := .env
ENV_PROD     := .env.production
COMPOSE      := docker compose --env-file $(ENV_DEV)
COMPOSE_PROD := docker compose --env-file $(ENV_PROD) -f docker-compose.prod.yml

.DEFAULT_GOAL := help

.PHONY: help dev build down restart logs shell \
        install key \
        artisan tinker migrate fresh seed test clear-cache optimize \
        env-prod check-prod prod build-prod down-prod logs-prod shell-prod artisan-prod \
        deploy

help: ## Show available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) \
	  | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-15s\033[0m %s\n", $$1, $$2}'

# ── Dev environment ──────────────────────────────────────────────────────────

dev: ## Start dev environment (builds image if needed)
	$(COMPOSE) up -d

build: ## Build dev image
	$(COMPOSE) build

down: ## Stop and remove containers
	$(COMPOSE) down

restart: ## Restart dev environment
	$(MAKE) down
	$(MAKE) dev

logs: ## Tail logs — pass service=<name> to filter
	$(COMPOSE) logs -f $(service)

shell: ## Open shell in app container
	$(COMPOSE) exec app bash

# ── First-time setup ─────────────────────────────────────────────────────────

install: ## Build image, install PHP deps, generate key, migrate
	[ -f .env ] || cp .env.example .env
	$(MAKE) build
	$(MAKE) dev
	$(COMPOSE) exec app composer install
	$(MAKE) key
	$(MAKE) migrate

key: ## Generate application key if missing
	$(COMPOSE) exec app php artisan key:generate

# ── Artisan ───────────────────────────────────────────────────────────────────

artisan: ## Run artisan: make artisan cmd="route:list"
	$(COMPOSE) exec app php artisan $(cmd)

tinker: ## Open a tinker REPL in the app container
	$(COMPOSE) exec app php artisan tinker

migrate: ## Run database migrations
	$(COMPOSE) exec app php artisan migrate

fresh: ## Drop all tables, re-migrate and seed
	$(COMPOSE) exec app php artisan migrate:fresh --seed

seed: ## Run database seeders
	$(COMPOSE) exec app php artisan db:seed

test: ## Run the PHPUnit test suite
	$(COMPOSE) exec app php artisan test

clear-cache: ## Clear config/route/view/application caches
	$(COMPOSE) exec app php artisan optimize:clear

optimize: ## Cache config/routes/views for production
	$(COMPOSE) exec app php artisan optimize

# ── Prod (docker-compose.prod.yml) ────────────────────────────────────────────

env-prod: ## Create .env.production from .env.production.example (owner-only), if it doesn't exist
	@if [ -f $(ENV_PROD) ]; then echo "$(ENV_PROD) already exists."; \
	else cp .env.production.example $(ENV_PROD) && chmod 600 $(ENV_PROD) && echo "Created $(ENV_PROD): fill in every empty value."; fi

check-prod: ## Check .env.production is safe to run with (values, duplicates, permissions, not in git)
	@sh ./.scripts/check-prod-env.sh $(ENV_PROD)

prod: check-prod ## Start the production stack
	$(COMPOSE_PROD) up -d

build-prod: check-prod ## Build the production image on the latest base and recreate the stack with it and .env.production
	$(COMPOSE_PROD) build --pull
	$(COMPOSE_PROD) up -d --force-recreate --remove-orphans
	$(COMPOSE_PROD) ps

down-prod: ## Stop and remove production containers
	$(COMPOSE_PROD) down

logs-prod: ## Tail production logs — pass service=<name> to filter
	$(COMPOSE_PROD) logs -f $(service)

shell-prod: ## Open a shell in the production app container, as www-data
	$(COMPOSE_PROD) exec -u www-data app bash

artisan-prod: ## Run artisan in production, as www-data: make artisan-prod cmd="migrate:status"
	$(COMPOSE_PROD) exec -u www-data app php artisan $(cmd)

# ── Deploy ─────────────────────────────────────────────────────────────────────

deploy: ## Deploy to production (on the app server): audit, pull, composer, migrate, caches — all in the app image
	bash ./.scripts/deploy.sh
