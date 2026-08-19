# All tools run inside the pinned Docker image (docker/Dockerfile, service "php" in docker-compose.yml).
# Local PHP is never used. Docker runs with userns remapping (rootless): container root == host user.
#
# RUN   skips the postgres dependency — static tools and unit tests don't need a database.
# RUNDB starts postgres (healthcheck-gated) — migrations, integration tests, full test run.
RUN   := docker compose run --rm --no-deps php
RUNDB := docker compose run --rm php

.PHONY: image install update require test test-unit test-integration test-browser test-filter test-file coverage mutation openapi docs schema check-values lint stan cs cs-fix validate audit deptrac migrate db-test console ci shell

image: ## Build the dev/test image
	docker compose build

install: image
	$(RUN) composer install

update: image
	$(RUN) composer update

require: ## Add a dependency: make require PACKAGES="symfony/twig-bundle" [DEV=1]
	@[ -n "$(PACKAGES)" ] || { echo 'Set PACKAGES, e.g. make require PACKAGES="symfony/twig-bundle"'; exit 1; }
	$(RUN) composer require $(if $(DEV),--dev,) $(PACKAGES)

migrate: ## Apply migrations to the dev database
	$(RUNDB) bin/console doctrine:migrations:migrate --no-interaction

db-test: ## Create the test database and bring its schema up to date
	$(RUNDB) bin/console doctrine:database:create --env=test --if-not-exists
	$(RUNDB) bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration

test: db-test
	$(RUNDB) vendor/bin/phpunit

test-unit: ## Fast loop: domain tests only, no database
	$(RUN) vendor/bin/phpunit --testsuite unit

test-integration: db-test ## Api + Web + Infrastructure tests against the compose postgres
	$(RUNDB) vendor/bin/phpunit --testsuite integration

test-browser: db-test ## Drive the pages in a real (headless) browser
	$(RUNDB) vendor/bin/phpunit --testsuite browser

test-filter: db-test ## One test or a group of them: make test-filter FILTER=FormApiTest::testSaveDraft
	@[ -n "$(FILTER)" ] || { echo 'Set FILTER, e.g. make test-filter FILTER=FormApiTest'; exit 1; }
	$(RUNDB) vendor/bin/phpunit --filter '$(FILTER)'

test-file: db-test ## One test file or directory: make test-file FILE=tests/Http/FormApiTest.php
	@[ -n "$(FILE)" ] || { echo 'Set FILE, e.g. make test-file FILE=tests/Http/FormApiTest.php'; exit 1; }
	$(RUNDB) vendor/bin/phpunit '$(FILE)'

coverage: db-test
	$(RUNDB) vendor/bin/phpunit --coverage-text

mutation: ## Mutation testing (Infection): src/Domain only, unit suite, no database
	$(RUN) vendor/bin/infection --threads=max --no-progress --test-framework-options="--testsuite=unit"

docs: ## Generate the API contract (NelmioApiDocBundle) into docs/ + a Markdown reference
	$(RUN) sh -c 'bin/console nelmio:apidoc:dump --format=yaml > docs/openapi.yaml'
	$(RUN) php tools/build-docs.php

openapi: docs ## Validate the generated contract against the OpenAPI 3.1 schema
	$(RUN) vendor/bin/php-openapi validate docs/openapi.yaml

schema: ## Values schema derived from a definition: make schema DEFINITION=tests/_requests/examples/definition.json [MODE=draft]
	@[ -n "$(DEFINITION)" ] || { echo 'Set DEFINITION, e.g. make schema DEFINITION=tests/_requests/examples/definition.json'; exit 1; }
	$(RUN) bin/console app:forms:schema '$(DEFINITION)' --mode=$(or $(MODE),strict)

check-values: ## Would the API take this JSON? make check-values DEFINITION=… VALUES=… [MODE=strict]
	@[ -n "$(DEFINITION)" ] && [ -n "$(VALUES)" ] || { echo 'Set DEFINITION and VALUES, e.g. make check-values DEFINITION=tests/_requests/examples/definition.json VALUES=tests/_requests/examples/values-partial.json'; exit 1; }
	$(RUN) bin/console app:forms:check-values '$(DEFINITION)' '$(VALUES)' --mode=$(or $(MODE),draft)

lint: ## Syntax check every PHP file against the runtime this project targets
	$(RUN) sh -c 'find src tests tools migrations public -name "*.php" -print0 | xargs -0 -n1 php -l | grep -v "No syntax errors" || true'

stan:
	$(RUN) vendor/bin/phpstan analyse --no-progress --memory-limit=512M

cs:
	$(RUN) vendor/bin/php-cs-fixer fix --dry-run --diff

cs-fix:
	$(RUN) vendor/bin/php-cs-fixer fix

validate:
	$(RUN) composer validate --strict

audit: ## Known security vulnerabilities in dependencies (abandoned packages: report, don't fail)
	$(RUN) composer audit --abandoned=report

deptrac: ## Module boundaries (deptrac.yaml)
	$(RUN) vendor/bin/deptrac analyse --no-progress --cache-file=.cache/deptrac.cache

# `openapi` regenerates docs/ first: the contract tests validate real traffic against
# the generated docs/openapi.yaml, so it must be current.
ci: validate cs openapi stan deptrac test mutation audit ## Everything the git pipeline checks

console: ## Any console command, in the container: make console CMD="doctrine:migrations:status"
	@[ -n "$(CMD)" ] || { echo 'Set CMD, e.g. make console CMD="debug:router"'; exit 1; }
	$(RUNDB) bin/console $(CMD)

shell: ## Interactive shell inside the dev image
	docker compose run --rm --no-deps -it php sh
