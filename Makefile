# All tools run inside the pinned Docker image (docker/Dockerfile, service "php" in docker-compose.yml).
# Local PHP is never used. Docker runs with userns remapping (rootless): container root == host user.
#
# RUN   skips the postgres dependency — static tools and unit tests don't need a database.
# RUNDB starts postgres (healthcheck-gated) — migrations, integration tests, full test run.
RUN   := docker compose run --rm --no-deps php
RUNDB := docker compose run --rm php

.PHONY: image install update test test-unit coverage mutation openapi docs stan cs cs-fix validate audit deptrac migrate db-test ci shell

image: ## Build the dev/test image
	docker compose build

install: image
	$(RUN) composer install

update: image
	$(RUN) composer update

migrate: ## Apply migrations to the dev database
	$(RUNDB) bin/console doctrine:migrations:migrate --no-interaction

db-test: ## Create the test database and bring its schema up to date
	$(RUNDB) bin/console doctrine:database:create --env=test --if-not-exists
	$(RUNDB) bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration

test: db-test
	$(RUNDB) vendor/bin/phpunit

test-unit: ## Fast loop: domain tests only, no database
	$(RUN) vendor/bin/phpunit --testsuite unit

coverage: db-test
	$(RUNDB) vendor/bin/phpunit --coverage-text

mutation: ## Mutation testing (Infection): src/Domain only, unit suite, no database
	$(RUN) vendor/bin/infection --threads=max --no-progress --test-framework-options="--testsuite=unit"

openapi: ## Validate openapi.yaml against the OpenAPI 3.1 schema
	$(RUN) vendor/bin/php-openapi validate openapi.yaml

docs: ## Render openapi.yaml into docs/ (single-file spec + Markdown reference)
	$(RUN) php tools/build-docs.php

stan:
	$(RUN) vendor/bin/phpstan analyse --no-progress --memory-limit=512M

cs:
	$(RUN) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	$(RUN) vendor/bin/php-cs-fixer fix

validate:
	$(RUN) composer validate --strict

audit: ## Known security vulnerabilities in dependencies (abandoned transitive dev deps: report, don't fail)
	$(RUN) composer audit --abandoned=report

deptrac: ## Module boundaries (deptrac.yaml)
	$(RUN) vendor/bin/deptrac analyse --no-progress --cache-file=.cache/deptrac.cache

# `docs` runs before `test`: the contract tests validate real traffic against the
# generated docs/openapi.yaml, so it must be current.
ci: validate cs openapi docs stan deptrac test mutation audit ## Everything the git pipeline checks

shell: ## Interactive shell inside the dev image
	docker compose run --rm --no-deps -it php sh
