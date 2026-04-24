# Skillhub

Plateforme de formations en ligne composée d'une API métier **Laravel 12** et d'un microservice d'authentification **Spring Boot 4**. L'ensemble est orchestré via **Docker Compose** et couvert par une pipeline **GitHub Actions** avec analyse **SonarCloud**.

---

## 1. Architecture microservices

```
┌───────────────┐        HTTP /auth/login               ┌──────────────────────┐
│               │ ─────────────────────────────────▶   │   Spring Boot SSO    │
│   Laravel API │                                       │   (port 8081)        │
│   (port 8000) │ ◀─────────────────────────────────   │                      │
│               │           JWT (HS384)                 │                      │
└──────┬────────┘                                       └──────────┬───────────┘
       │                                                           │
       ▼                                                           ▼
┌───────────────┐                                       ┌──────────────────────┐
│   MySQL 8     │                                       │  PostgreSQL 16       │
│  skillhubsql  │                                       │  sso_users           │
│  (port 3307)  │                                       │  (port 5433)         │
└───────────────┘                                       └──────────────────────┘
```

| Composant | Technologie | Rôle | Port hôte |
|-----------|-------------|------|-----------|
| `skillhub-laravel` | Laravel 12 / PHP 8.2 | API métier (formations, inscriptions, progression) | `8000` |
| `skillhub-sso`     | Spring Boot 4 / Java 21 | Authentification centralisée + émission / validation JWT | `8081` |
| `skillhub-mysql`   | MySQL 8.0 | Données métier Laravel | `3307` |
| `skillhub-postgres`| PostgreSQL 16 | Comptes utilisateurs SSO | `5433` |

**Choix d'architecture.** L'authentification est séparée du domaine métier pour pouvoir la réutiliser depuis d'autres clients (web, mobile, services tiers), pour isoler la base des identifiants dans son propre SGBD, et pour que l'API Laravel n'ait plus à stocker ni valider de mots de passe.

---

## 2. Authentification SSO

### Flux

1. Le client envoie `POST /api/sso/login` à Laravel avec `username` + `password`.
2. Laravel relaie la requête au microservice via `POST http://sso:8081/auth/login` (classe [`SsoClient`](skillhub_api/app/Services/SsoClient.php)).
3. Spring Boot vérifie le hash **BCrypt** en base Postgres, puis signe un **JWT HS384** avec les claims `iss=skillhub-sso`, `aud=skillhub-laravel`, `role`, `exp (3600s)`.
4. Laravel renvoie le token au client.
5. Le client rappelle une route protégée avec l'en-tête `Authorization: Bearer <jwt>`.
6. Le middleware [`SsoAuthenticate`](skillhub_api/app/Http/Middleware/SsoAuthenticate.php) appelle `GET /auth/validate` côté Spring, qui vérifie signature + issuer + audience + expiration.
7. Les claims retournés sont injectés dans la requête Laravel et la route s'exécute.

### Secret JWT

Clé symétrique (base64) lue dans la variable d'environnement `SA_JWT_SECRET` côté Spring. Elle n'est jamais en clair dans les fichiers versionnés : seule une valeur de développement est définie dans `docker-compose.yml` pour lancer la stack localement, et en production elle vient d'un secret GitHub Actions / variable d'orchestrateur.

### Routes protégées côté Laravel

```php
Route::middleware('sso')->group(function () {
    Route::get('/sso/me', [AuthSsoController::class, 'me']);
    // ajouter ici d'autres routes à protéger
});
```

---

## 3. Règle métier (Q1) : limite d'inscriptions

**Règle.** Un apprenant ne peut pas être inscrit à plus de **5 formations en cours** simultanément.

**Endpoint modifié.** `POST /api/learner/enrollments` → [`LearnerEnrollmentController@store`](skillhub_api/app/Http/Controllers/Api/LearnerEnrollmentController.php).

**Comportement.**

- Avant la création d'une nouvelle inscription, le contrôleur compte les inscriptions de l'utilisateur dont `progress` est `NULL` ou `< 100`.
- Si le compteur `>= 5`, renvoie :
  ```http
  HTTP/1.1 400 Bad Request
  { "message": "Vous ne pouvez pas etre inscrit a plus de 5 formations simultanement" }
  ```
- Sinon, l'inscription est persistée normalement.

**Tests.** [`tests/Feature/LearnerEnrollmentLimitTest.php`](skillhub_api/tests/Feature/LearnerEnrollmentLimitTest.php) couvre les deux cas :
- refus au-delà de 5 inscriptions actives ;
- acceptation en dessous de la limite.

---

## 4. Installation et lancement

### Prérequis

- Docker Desktop (ou Docker Engine + Compose v2)
- 4 Go de RAM libres

### Lancer la stack

```bash
git clone <repo-url>
cd EC06_202616
docker compose up --build
```

Au premier démarrage Docker construit les deux images, puis les conteneurs attendent les healthchecks MySQL / Postgres avant de démarrer. Laravel applique automatiquement les migrations (`php artisan migrate --force` dans [`entrypoint.sh`](skillhub_api/docker/entrypoint.sh)) et Spring amorce l'utilisateur de démo via `UserSeeder`.

### Vérification rapide

```bash
# 1. Obtenir un JWT
curl -X POST http://localhost:8000/api/sso/login \
  -H "Content-Type: application/json" \
  -d '{"username":"apprenant@skillhub.test","password":"Skillhub123!"}'

# 2. Appeler une route protégée avec le token reçu
curl http://localhost:8000/api/sso/me \
  -H "Authorization: Bearer <jwt>"
```

### Arrêt

```bash
docker compose down           # arrêt
docker compose down -v        # arrêt + suppression des volumes (reset DBs)
```

---

## 5. Outillage DevOps

### Docker

Chaque service a son `Dockerfile` :

- [`skillhub_api/Dockerfile`](skillhub_api/Dockerfile) — PHP 8.2 Alpine avec extensions `pdo_mysql`, `mbstring`, `zip`, `bcmath`, dépendances installées par Composer, lancé via [`docker/entrypoint.sh`](skillhub_api/docker/entrypoint.sh).
- [`Skillhub_springboot/Dockerfile`](Skillhub_springboot/Dockerfile) — build multi-stage Maven → image runtime `eclipse-temurin:21-jre`.

[`docker-compose.yml`](docker-compose.yml) orchestre les 4 conteneurs sur un réseau `skillhub-net`, déclare des volumes persistants (`mysql-data`, `postgres-data`) et des healthchecks sur les bases.

### GitHub Actions (CI/CD)

Workflow : [`.github/workflows/ci-cd.yml`](.github/workflows/ci-cd.yml). Déclenché sur `push` et `pull_request` vers `dev` et `main`.

Étapes dans l'ordre :

| Job | Étape | Outil |
|-----|-------|-------|
| `laravel-ci` | Checkout → Install → Lint → Test (+ coverage) | Composer, Laravel Pint, PHPUnit + pcov |
| `spring-ci`  | Checkout → Install → Lint → Test (+ coverage) | Maven, `mvn compile`, `mvn verify` + JaCoCo |
| `sonarcloud` | Analyse croisée PHP + Java | `SonarSource/sonarqube-scan-action@v4` |
| `build-and-push` | Build & push des images (uniquement sur push `dev` / `main`) | `docker/build-push-action@v6` |

**Secrets / variables GitHub** (aucun credential en clair dans le dépôt) :

- Secrets : `SONAR_TOKEN`, `REGISTRY_USER`, `REGISTRY_TOKEN`
- Variables : `SONAR_ORG`, `SONAR_PROJECT_KEY`, optionnel `REGISTRY_URL` (défaut `docker.io`)

### SonarCloud

Configuration : [`sonar-project.properties`](sonar-project.properties). Un seul scan couvre les deux langages :

- Sources PHP : `skillhub_api/app`, `config`, `routes`, `database`
- Sources Java : `Skillhub_springboot/src/main/java`
- Couverture PHP : rapport Clover produit par PHPUnit
- Couverture Java : rapport JaCoCo XML produit par `mvn verify`

Les artefacts de couverture sont passés entre jobs via `actions/upload-artifact` puis récupérés par le job `sonarcloud`, ce qui évite de relancer les tests dans le scan.

---

## 6. Arborescence

```
EC06_202616/
├── skillhub_api/               # API Laravel (métier)
│   ├── app/ config/ routes/ database/ tests/
│   ├── docker/entrypoint.sh
│   └── Dockerfile
├── Skillhub_springboot/        # Microservice SSO
│   ├── src/main/java/mcci/auth/sa_backend/
│   └── Dockerfile
├── .github/workflows/ci-cd.yml # Pipeline CI/CD
├── sonar-project.properties    # Analyse SonarCloud
├── docker-compose.yml          # Orchestration locale
└── README.md
```
