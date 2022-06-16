include .dbconfig

make:
	@echo "Please choose one of the following target:"
	@echo ""
	@echo " install  Installs required packages"
	@echo " test     Runs tests"
	@echo ""

install:
	@composer install
	@mkdir -p tests/bin
	@wget -O tests/bin/install.sh https://raw.githubusercontent.com/wp-cli/scaffold-command/v1.1.3/templates/install-wp-tests.sh
	@chmod +x tests/bin/install.sh
	@tests/bin/install.sh $(DB) $(USER) $(PASS) $(HOST)

test:
	@vendor/bin/phpunit
