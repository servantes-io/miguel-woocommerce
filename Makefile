static_langs = $(patsubst %.po, %.mo, $(wildcard languages/*.po))

include .dbconfig

make:
	@echo "Please choose one of the following target:"
	@echo ""
	@echo " install      Installs required packages"
	@echo " test         Runs tests locally"
	@echo " test-docker  Runs tests in Docker (no local tools needed)"
	@echo " smoke-docker Installs the plugin into a real shop in Docker and checks it"
	@echo " pack         Creates a zip file with the plugin"
	@echo ""

install:
	composer install
	mkdir -p tests/bin
	wp scaffold plugin-tests miguel --ci=gitlab
	tests/bin/install-wp-tests.sh $(DB) $(USER) $(PASS) $(HOST)

test:
	vendor/bin/phpunit

test-docker:
	docker compose -f docker-compose.test.yml run --rm phpunit

smoke-docker:
	scripts/smoke_docker.sh

build: $(static_langs)

pack: build
	.github/scripts/build-zip.sh

# Rule to convert .po files into .mo
%.mo: %.po
	msgfmt -o $@ $^
