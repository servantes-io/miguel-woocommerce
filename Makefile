static_langs = $(patsubst %.po, %.mo, $(wildcard languages/*.po))

include .dbconfig

# The Docker test targets take the lock scripts/phpunit_docker.sh takes (same file),
# so a make run never shares the version-named cache volumes or the test database
# with a script run; a machine without flock (macOS) runs without it. (LOCK is
# expanded where it is used, so the lock file is only touched by those targets.)
LOCK_FILE = /tmp/miguel-woocommerce-phpunit.lock
LOCK = $(shell command -v flock >/dev/null 2>&1 && { [ -e $(LOCK_FILE) ] || : >$(LOCK_FILE); echo "flock $(LOCK_FILE)"; })

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
	$(LOCK) docker compose --env-file .test-versions -f docker-compose.test.yml run --build --rm phpunit

smoke-docker:
	scripts/smoke_docker.sh

build: $(static_langs)

pack: build
	.github/scripts/build-zip.sh

# Rule to convert .po files into .mo
%.mo: %.po
	msgfmt -o $@ $^
