languages = $(wildcard languages/*.po)
static_langs = $(patsubst %.po, %.mo, $(wildcard languages/*.po))

include .dbconfig

make:
	@echo "Please choose one of the following target:"
	@echo ""
	@echo " install  Installs required packages"
	@echo " test     Runs tests"
	@echo ""

install:
	composer install
	mkdir -p tests/bin
	wp scaffold plugin-tests miguel --ci=gitlab
	tests/bin/install-wp-tests.sh $(DB) $(USER) $(PASS) $(HOST)

test:
	vendor/bin/phpunit

pack: $(static_langs)
	zip -r miguel-$${CI_COMMIT_TAG:=dev}.zip assets/ includes/ languages/ composer.json miguel.php readme.md


# Rule to convert .po files into .mo
%.mo: %.po
	msgfmt -o $@ $^
