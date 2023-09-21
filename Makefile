static_langs = $(patsubst %.po, %.mo, $(wildcard src/languages/*.po))

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
	bin/pack_plugin.sh

# Rule to convert .po files into .mo
%.mo: %.po
	msgfmt -o $@ $^
