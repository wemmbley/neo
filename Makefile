#!/usr/bin/make
# Makefile readme (ru): <http://linux.yaroslavl.ru/docs/prog/gnu_make_3-79_russian_manual.html>
# Makefile readme (en): <https://www.gnu.org/software/make/manual/html_node/index.html#SEC_Contents>

SHELL = /bin/bash
DC_RUN_ARGS = --rm --user "$(shell id -u):$(shell id -g)"

.PHONY : help shell logs
.DEFAULT_GOAL : help

# This will output the help for each task. thanks to https://marmelab.com/blog/2016/02/29/auto-documented-makefile.html
help: ## Show this help
	@printf "\033[33m%s:\033[0m\n" 'Available commands'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_-]+:.*?## / {printf "  \033[32m%-18s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)

logs: ## Show nginx logs
	docker logs nginx >/dev/null

shell: ## Start shell
	docker-compose run $(DC_RUN_ARGS) php-fpm sh

ps: ## Get active containers
	docker-compose ps -a

up: ## Run containers
	docker-compose up -d

down: ## Stop containers
	docker-compose down

build: ## Build or rebuild containers
	docker-compose build