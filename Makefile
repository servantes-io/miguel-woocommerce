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
	pushd src > /dev/null; \
		zip -r ../$${CI_COMMIT_TAG:=dev}.zip *; \
	popd > /dev/null; \
	zip $${CI_COMMIT_TAG:=dev}.zip composer.json README.md

lint:
	composer exec -- phpcs --standard=./phpcs.xml --warning-severity=0 --report=code --ignore-annotations --extensions=php,html -s src

# Rule to convert .po files into .mo
%.mo: %.po
	msgfmt -o $@ $^
