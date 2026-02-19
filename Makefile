.PHONY: help build up down restart logs shell clean ps scan create-user

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

rebuild: ## Führt clean, build und up aus
	make down
	make build
	make up

scan: ## Führt einen manuellen Scan aus
	docker compose exec tls-monitor php /var/www/html/cli/scan.php

scan-force: ## Führt einen manuellen Scan aus (erzwingt Scan auch wenn heute bereits erfolgreich)
	docker compose exec tls-monitor php /var/www/html/cli/scan.php --force

create-user: ## Erstellt einen neuen Benutzer (Übergabe: ARGS="user pass role"  oder USERNAME/PASSWORD/ROLE)
	@if [ -n "$(ARGS)" ]; then \
		docker compose exec tls-monitor php /var/www/html/cli/create_user.php $(ARGS); \
	elif [ -n "$(USERNAME)" ] && [ -n "$(PASSWORD)" ]; then \
		ROLE=$${ROLE:-auditor}; \
		docker compose exec tls-monitor php /var/www/html/cli/create_user.php "$(USERNAME)" "$(PASSWORD)" "$$ROLE"; \
	else \
		@echo "Usage: make create-user USERNAME=<user> PASSWORD=<pass> [ROLE=admin|auditor]"; \
		@echo "       make create-user ARGS=\"user pass role\""; \
		@echo "       (oder direkt: docker compose exec tls-monitor php /var/www/html/cli/create_user.php user pass role)"; \
		false; \
	fi

db-backup: ## Erstellt ein Backup der Datenbank
	docker compose exec tls-monitor cp /var/www/html/data/database.sqlite /var/www/html/data/database.sqlite.backup.$(shell date +%Y%m%d_%H%M%S)

db-restore: ## Stellt das neueste Backup wieder her
	@echo "Verfügbare Backups:"
	@docker compose exec tls-monitor ls -lh /var/www/html/data/database.sqlite.backup.* 2>/dev/null || echo "Keine Backups gefunden"

install: ## Initialisiert das Projekt (Build + Up)
	make build
	make up
	@echo ""
	@echo "✓ Container gestartet!"
	@echo "✓ Anwendung läuft auf: http://localhost:8443"
	@echo ""
	@echo "Nächste Schritte:"
	@echo "  1. Erstelle einen Benutzer: make create-user"
	@echo "  2. Öffne http://localhost:8443 im Browser"
