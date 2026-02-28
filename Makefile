.PHONY: help build up down restart logs shell clean ps scan create-user test test-unit test-integration test-coverage lint

help: ## Zeigt diese Hilfe an
	@echo "Verfügbare Befehle:"
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

build: ## Baut die Docker-Container
	docker compose build

up: ## Startet die Container im Hintergrund
	docker compose up -d

down: ## Stoppt die Container
	docker compose down

restart: ## Startet die Container neu
	docker compose restart

logs: ## Zeigt die Container-Logs an (Ctrl+C zum Beenden)
	docker compose logs -f

logs-tail: ## Zeigt die letzten 100 Zeilen der Logs
	docker compose logs --tail=100

shell: ## Öffnet eine Shell im Container
	docker compose exec tls-monitor bash

ps: ## Zeigt den Status der Container
	docker compose ps

clean: ## Stoppt Container und löscht Volumes
	docker compose down -v

clean-all: ## Löscht Container, Volumes und Images
	docker compose down -v --rmi all

purge: ## Löscht ALLES: Container, Volumes, Images und Host-seitige Build-Artefakte (vendor/, var/cache/)
	docker compose down -v --rmi all --remove-orphans || true
	rm -rf vendor/ var/cache/
	@echo "Alles bereinigt. Weiter mit: make install"

rebuild: ## Führt clean, build und up aus
	make down
	make build
	make up

scan: ## Führt einen manuellen Scan aus
	docker compose exec tls-monitor php /var/www/html/bin/console app:scan

scan-force: ## Führt einen manuellen Scan aus (erzwingt Scan auch wenn heute bereits erfolgreich)
	docker compose exec tls-monitor php /var/www/html/bin/console app:scan --force

create-user: ## Erstellt einen neuen Benutzer (Übergabe: USERNAME=user PASSWORD=pass [ROLE=admin|auditor])
	@if [ -n "$(USERNAME)" ] && [ -n "$(PASSWORD)" ]; then \
		ROLE=$${ROLE:-auditor}; \
		docker compose exec tls-monitor php /var/www/html/bin/console app:create-user "$(USERNAME)" "$(PASSWORD)" --role="$$ROLE"; \
	else \
		echo "Usage: make create-user USERNAME=<user> PASSWORD=<pass> [ROLE=admin|auditor]"; \
		false; \
	fi

console: ## Führt eine Symfony-Konsolen-Befehl aus (Übergabe: CMD="befehl")
	docker compose exec tls-monitor php /var/www/html/bin/console $(CMD)

db-backup: ## Erstellt ein Backup der Datenbank
	docker compose exec tls-monitor cp /var/www/html/data/tls_monitor.sqlite /var/www/html/data/tls_monitor.sqlite.backup.$(shell date +%Y%m%d_%H%M%S)

deploy:
	git pull
	make down
	make install

install: ## Initialisiert das Projekt (Build + Up + Composer Install + Migrations)
	make build
	make up
	sleep 3
	docker compose exec -e COMPOSER_MEMORY_LIMIT=-1 tls-monitor composer install --no-interaction
	docker compose exec tls-monitor php /var/www/html/bin/console doctrine:migrations:migrate --no-interaction
	@echo ""
	@echo "Container gestartet!"
	@echo "Anwendung läuft auf: http://localhost:8443"
	@echo "Standard-Login: admin / admin"
	@echo ""
	@echo "Benutzer erstellen: make create-user USERNAME=alice PASSWORD=secret ROLE=admin"

test: ## Führt alle PHPUnit Tests aus
	docker compose exec -e APP_ENV=test tls-monitor php /var/www/html/bin/phpunit

test-unit: ## Führt nur Unit Tests aus
	docker compose exec -e APP_ENV=test tls-monitor php /var/www/html/bin/phpunit tests/Unit

test-integration: ## Führt nur Integration Tests aus
	docker compose exec -e APP_ENV=test tls-monitor php /var/www/html/bin/phpunit tests/Integration

test-coverage: ## Führt Tests mit Code Coverage aus
	docker compose exec -e APP_ENV=test tls-monitor php /var/www/html/bin/phpunit --coverage-html=var/coverage

lint: ## Führt PHPStan statische Analyse aus
	docker compose exec tls-monitor php -d memory_limit=512M /var/www/html/vendor/bin/phpstan analyse --no-progress

insights: ## Führt PHPInsights Code-Quality-Analyse aus
	docker compose exec tls-monitor php -d memory_limit=1G /var/www/html/vendor/bin/phpinsights analyse src --no-interaction --disable-security-check --composer /var/www/html/composer.lock
