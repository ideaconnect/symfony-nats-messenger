.PHONY: run-unit-tests setup-functional-tests run-functional-tests run-nats

run-nats:
	cd tests/nats && docker compose up -d

stop-nats:
	cd tests/nats && docker compose down -v

run-unit-tests:
	XDEBUG_MODE=coverage ./vendor/bin/phpunit --configuration phpunit.xml.dist --coverage-clover clover.xml --coverage-text --colors=never

setup-functional-tests:
	cd tests/functional && composer install --prefer-dist --no-progress --no-suggest

run-functional-tests:
	cd tests/functional && vendor/bin/behat --tags="~@extreme"