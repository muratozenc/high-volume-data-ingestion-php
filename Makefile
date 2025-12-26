.PHONY: help install up down migrate seed test clean

help:
	@echo "Available commands:"
	@echo "  make install    - Install PHP dependencies"
	@echo "  make up         - Start Docker containers"
	@echo "  make down       - Stop Docker containers"
	@echo "  make migrate    - Run database migrations"
	@echo "  make seed       - Seed sample data"
	@echo "  make test       - Run tests"
	@echo "  make clean      - Clean up containers and volumes"

install:
	composer install

up:
	docker-compose up -d

down:
	docker-compose down

migrate:
	docker-compose exec php php bin/migrate.php

seed:
	docker-compose exec php php bin/seed.php

test:
	docker-compose exec php composer test

clean:
	docker-compose down -v

