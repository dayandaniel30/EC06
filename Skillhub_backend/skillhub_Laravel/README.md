# SkillHub API (Laravel)

API Laravel pour l'authentification et la gestion des formations formateur.

## Stack
- PHP 8.2+
- Laravel 12
- Laravel Sanctum (tokens API)
- MySQL

## Fonctionnalites
- Auth: inscription, connexion, profil courant, deconnexion, generation de token.
- Formateur: CRUD des formations du formateur connecte.
- Filtres sur la liste des formations (`q`, `min_price`, `max_price`).

## Prerequis
- PHP 8.2+
- Composer
- MySQL (base `skillhubsql` recommandee par le projet)

## Installation
```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configurer ensuite la base dans `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=skillhubsql
DB_USERNAME=root
DB_PASSWORD=
```

Puis migrer:
```bash
php artisan migrate
```

## Lancer l'API
```bash
php artisan serve
```
API locale: `http://127.0.0.1:8000`

## Authentification
- Sanctum Bearer Token via header:
  - `Authorization: Bearer <token>`

## Routes principales

### Public
- `POST /api/register`
- `POST /api/login`

### Protegees (auth:sanctum)
- `POST /api/logout`
- `GET /api/user`
- `GET /api/me`
- `POST /api/token`
- `GET /api/my-formations`
- `POST /api/formations`
- `PUT|PATCH /api/formations/{formation}`
- `DELETE /api/formations/{formation}`

### Groupe formateur (auth:sanctum)
- `GET /api/formateur/formations`
- `POST /api/formateur/formations`
- `PUT|PATCH /api/formateur/formations/{formation}`
- `DELETE /api/formateur/formations/{formation}`

## Documentation OpenAPI
- Fichier: `openapi.yaml`
- Couvre principalement la creation et la liste des formations.

## Tests
```bash
php artisan test
```

## Notes techniques
- Les actions formations sont reservees aux roles `formateur` et `admin` (selon endpoint).
- `GET /api/my-formations` est reserve au role `formateur`.
- L'auth controller force l'usage de la base `skillhubsql` pour login/register.

## Note sur l'utilisation de l'IA
Ce projet a utilise l'IA comme outil d'assistance (aide a la structuration, verification et documentation), mais le travail principal a ete realise manuellement: conception, integration, corrections, tests et decisions techniques. L'IA a servi d'appui, tandis que l'effort et la realisation du projet reposent sur mon investissement personnel.
