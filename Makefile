COMPOSE      := docker compose
COMPOSE_PROD := docker compose -f docker-compose.prod.yml

.DEFAULT_GOAL := help

.PHONY: help dev build down restart logs shell \
        install key \
        artisan tinker migrate fresh seed test clear-cache optimize \
        prod build-prod down-prod logs-prod \
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

prod: ## Start the production stack
	$(COMPOSE_PROD) up -d

build-prod: ## Build the production image
	$(COMPOSE_PROD) build

down-prod: ## Stop and remove production containers
	$(COMPOSE_PROD) down

logs-prod: ## Tail production logs — pass service=<name> to filter
	$(COMPOSE_PROD) logs -f $(service)

# ── Deploy ─────────────────────────────────────────────────────────────────────

deploy: ## Run the production deploy script — intended for the app server, not local dev
	sh ./.scripts/deploy.sh
