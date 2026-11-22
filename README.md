# arctron-purchases-dashboard

Stack: **Symfony** (backend) · **PostgreSQL** (banco) · **React + Vite + TypeScript** (frontend)

## Estrutura

```
backend/   Symfony 7 (skeleton + Doctrine ORM)
frontend/  React + Vite + TypeScript
docker-compose.yml
```

## Subir o projeto

```bash
docker compose up -d --build
```

| Serviço    | URL                     |
|------------|-------------------------|
| Backend    | http://localhost:8088   |
| Frontend   | http://localhost:5173   |
| PostgreSQL | localhost:5432 (app/app, db `app`) |

## Comandos úteis

```bash
docker compose exec backend php bin/console about
docker compose exec backend composer require <pacote>
docker compose exec backend php bin/console doctrine:migrations:migrate
```
