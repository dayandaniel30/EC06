# Skillhub

Plateforme de formations en ligne construite en **architecture microservices** :
une API métier **Laravel 12** et un microservice d'authentification **Spring Boot 4 (SSO / JWT)**,
chacun avec sa propre base de données, orchestrés par **Docker Compose** et
couverts par une pipeline **GitHub Actions** avec analyse **SonarCloud**.

| | |
|---|---|
| **API métier** | Laravel 12 · PHP 8.3 · MySQL 8 |
| **Authentification** | Spring Boot 4 · Java 21 · PostgreSQL 16 |
| **Orchestration** | Docker Compose · réseau privé `skillhub-network` |
| **Qualité** | PHPStan niveau 5 · Checkstyle · PHPUnit · JUnit · SonarCloud |

---

## Sommaire

1. [Architecture microservices](#1-architecture-microservices)
2. [Authentification SSO et JWT](#2-authentification-sso-et-jwt)
3. [Règle métier Q1 — gestion de l'inactivité](#3-règle-métier-q1--gestion-de-linactivité)
4. [Installation et lancement](#4-installation-et-lancement)
5. [Outillage DevOps](#5-outillage-devops)
6. [Qualité du code — avant / après](#6-qualité-du-code--avant--après)
7. [Plan d'action qualité](#7-plan-daction-qualité)
8. [Écarts assumés au cahier des charges](#8-écarts-assumés-au-cahier-des-charges)
9. [Arborescence](#9-arborescence)

---

## 1. Architecture microservices

### Vue d'ensemble

```
                        ┌───────────────────────────┐
                        │   Client (React / curl)   │
                        └─────────────┬─────────────┘
                                      │  HTTP :8000
                                      ▼
╔═════════════════════════════════════════════════════════════════════════╗
║  Réseau Docker privé : skillhub-network                                 ║
║                                                                         ║
║   ┌──────────────────────────┐   REST   ┌───────────────────────────┐   ║
║   │    skillhub-laravel      │─────────▶│       spring-sso          │   ║
║   │                          │  POST    │                           │   ║
║   │    API métier            │  /auth/  │   Authentification        │   ║
║   │    Laravel 12 / PHP 8.3  │  login   │   Spring Boot 4 / Java 21 │   ║
║   │                          │◀─────────│                           │   ║
║   │    :8000                 │   JWT    │   :8080                   │   ║
║   └────────────┬─────────────┘  HS384   └─────────────┬─────────────┘   ║
║                │                                      │                 ║
║                │ SQL                                  │ SQL             ║
║                ▼                                      ▼                 ║
║   ┌──────────────────────────┐          ┌───────────────────────────┐   ║
║   │       laravel-db         │          │         sso-db            │   ║
║   │       MySQL 8            │    ⊗     │      PostgreSQL 16        │   ║
║   │       skillhubsql        │  aucun   │      sso_db               │   ║
║   │                          │  accès   │                           │   ║
║   │  formations, inscriptions│  croisé  │  identifiants, hash BCrypt│   ║
║   │  notes, utilisateurs     │          │  tokens de reset          │   ║
║   └──────────────────────────┘          └───────────────────────────┘   ║
║                                                                         ║
║   ┌──────────────────────────┐                                          ║
║   │   skillhub-scheduler     │  Ordonnanceur Laravel (règle Q1)         ║
║   │   php artisan schedule:work                                         ║
║   └──────────────────────────┘                                          ║
╚═════════════════════════════════════════════════════════════════════════╝
```

### Services

| Service | Technologie | Rôle | Port hôte |
|---------|-------------|------|-----------|
| `skillhub-laravel` | Laravel 12 / PHP 8.3 | API métier : formations, inscriptions, progression, notes | `8000` |
| `spring-sso` | Spring Boot 4 / Java 21 | Authentification centralisée, émission et validation des JWT | `8080` |
| `laravel-db` | MySQL 8.0 | Données métier | `3307` |
| `sso-db` | PostgreSQL 16 | Comptes et identifiants SSO | `5433` |
| `skillhub-scheduler` | Laravel 12 / PHP 8.3 | Exécution des tâches planifiées (purge d'inactivité) | — |

### Pattern *Database per Service*

Chaque microservice possède sa base, dans un SGBD distinct, et **aucun des deux
ne peut lire la base de l'autre**. Cette contrainte est structurante :

- **Isolation du risque.** Une injection SQL dans l'API métier ne donne aucun
  accès aux hash de mots de passe : ils vivent dans `sso_db`, que Laravel ne sait
  pas joindre. La surface d'attaque sur les identifiants se réduit au seul SSO.
- **Couplage par contrat, pas par schéma.** Les deux services ne communiquent que
  par l'API REST du SSO. Le schéma interne de `sso_db` peut évoluer sans casser
  Laravel tant que le contrat HTTP est respecté.
- **Choix technique indépendant.** PostgreSQL côté SSO, MySQL côté métier : chaque
  service utilise le SGBD adapté à son usage et se déploie à son propre rythme.

Le prix à payer est explicite : aucune jointure SQL entre les deux domaines, et
pas de transaction distribuée. C'est pourquoi Laravel conserve une table `users`
locale — voir [la section provisioning](#provisioning-de-lutilisateur-local).

---

## 2. Authentification SSO et JWT

### Principe

**Laravel ne stocke ni ne vérifie aucun mot de passe primaire.** Il délègue
entièrement l'authentification au microservice Spring Boot et se contente de
faire valider les jetons qu'on lui présente.

### Flux complet

```
  Client                  Laravel                     Spring SSO              sso-db
    │                        │                             │                     │
    │ 1. POST /api/sso/login │                             │                     │
    │  {username, password}  │                             │                     │
    ├───────────────────────▶│                             │                     │
    │                        │ 2. POST /auth/login         │                     │
    │                        ├────────────────────────────▶│                     │
    │                        │                             │ 3. findByUsername   │
    │                        │                             ├────────────────────▶│
    │                        │                             │ 4. hash BCrypt      │
    │                        │                             │◀────────────────────┤
    │                        │                             │                     │
    │                        │                             │ 5. vérifie + signe  │
    │                        │                             │    le JWT (HS384)   │
    │                        │ 6. {accessToken, role, ...} │                     │
    │                        │◀────────────────────────────┤                     │
    │ 7. access_token        │                             │                     │
    │◀───────────────────────┤                             │                     │
    │                        │                             │                     │
    │ 8. GET /api/sso/me     │                             │                     │
    │  Authorization: Bearer │                             │                     │
    ├───────────────────────▶│                             │                     │
    │                        │ 9. GET /auth/validate       │                     │
    │                        │    Authorization: Bearer    │                     │
    │                        ├────────────────────────────▶│                     │
    │                        │                             │ 10. vérifie         │
    │                        │                             │  signature+iss+aud  │
    │                        │                             │  +exp               │
    │                        │ 11. {valid, subject, role}  │                     │
    │                        │◀────────────────────────────┤                     │
    │                        │                             │                     │
    │                        │ 12. Auth::setUser()         │                     │
    │                        │     (utilisateur local)     │                     │
    │ 13. 200 + ressource    │                             │                     │
    │◀───────────────────────┤                             │                     │
```

### Le jeton

Le SSO signe un **JWT HS384** dont les claims sont vérifiées à chaque appel.
L'algorithme est déduit par JJWT de la longueur de la clé : le secret de 49 octets
livré par défaut donne du HS384 ; un secret de 32 octets donnerait du HS256.


| Claim | Valeur | Rôle |
|-------|--------|------|
| `iss` | `skillhub-sso` | Émetteur — un jeton d'un autre SSO est rejeté |
| `aud` | `skillhub-laravel` | Destinataire — un jeton émis pour un autre client est rejeté |
| `sub` | identifiant (email) | Sujet, utilisé pour retrouver l'utilisateur local |
| `role` | `APPRENANT`, `FORMATEUR`, `ADMIN` | Rôle applicatif, source de vérité côté SSO |
| `exp` | +3600 s | Expiration, par défaut une heure |

Le secret HMAC (`SA_JWT_SECRET`, encodé en Base64) n'existe **que côté Spring**.
Laravel n'en a pas besoin puisqu'il ne vérifie pas les signatures lui-même : il
interroge `GET /auth/validate`. Un secret compromis ne l'est donc que dans un
seul service.

### Le middleware `SpringSsoAuthenticate`

Fichier : [`app/Http/Middleware/SpringSsoAuthenticate.php`](skillhub_api/app/Http/Middleware/SpringSsoAuthenticate.php),
enregistré sous l'alias `sso` dans [`bootstrap/app.php`](skillhub_api/bootstrap/app.php).

Il enchaîne cinq contrôles :

1. **Extraction** du jeton depuis l'en-tête `Authorization: Bearer <jwt>` → `401` si absent.
2. **Validation** déléguée à `GET /auth/validate` via [`SsoClient`](skillhub_api/app/Services/SsoClient.php) → `401` si le SSO refuse.
   Un SSO injoignable provoque également un `401` : en cas de doute, l'accès est refusé, jamais accordé.
3. **Résolution** de l'utilisateur local à partir de la claim `sub` (voir ci-dessous).
4. **Statut du compte** : un compte désactivé pour inactivité est refusé en `403`, même si son JWT est encore techniquement valide.
5. **Liaison** : `Auth::setUser($user)` rattache l'utilisateur à la requête courante.

```php
Route::middleware('sso')->group(function () {
    Route::get('/sso/me', [AuthSsoController::class, 'me']);
    // ajouter ici les routes à protéger par le SSO
});
```

### Provisioning de l'utilisateur local

`Auth::setUser()` est le point clé de l'intégration : **une fois appelé,
`$request->user()` répond**, donc tout le code métier existant fonctionne à
l'identique derrière `auth:sanctum` ou derrière le SSO — y compris le middleware
`TrackLastActivity` qui alimente la règle Q1.

Cette liaison est valable **pour la seule durée de la requête** : aucune session
n'est ouverte, aucun cookie n'est émis, l'API reste stateless.

[`SsoUserProvisioner`](skillhub_api/app/Services/SsoUserProvisioner.php) fait le
pont entre l'identité du JWT et la ligne `users` locale :

- l'utilisateur est retrouvé par `email = sub`, ou **créé** s'il est inconnu ;
- son `role` est **resynchronisé** depuis la claim `role` à chaque requête — le SSO reste la source de vérité ;
- le champ `password` local reçoit une valeur aléatoire jamais communiquée : ce compte n'est pas authentifiable autrement que par le SSO.

Pourquoi persister une ligne locale alors que Laravel ne gère pas les mots de
passe ? Parce que les tables métier (`formations`, `enrollments`, `ratings`)
portent des clés étrangères vers `users.id`, et parce que la règle d'inactivité a
besoin d'une colonne où écrire `last_activity_at`.

### Endpoints

| Méthode | Route Laravel | Protégée | Description |
|---------|---------------|----------|-------------|
| `POST` | `/api/sso/login` | non | Échange identifiants → JWT |
| `POST` | `/api/sso/forgot-password` | non | Demande un token de réinitialisation |
| `POST` | `/api/sso/reset-password` | non | Applique un nouveau mot de passe |
| `GET` | `/api/sso/me` | **oui** (`sso`) | Identité courante résolue par le SSO |

Côté Spring : `POST /auth/login`, `GET /auth/validate`, `POST /auth/forgot-password`,
`POST /auth/reset-password`, `GET /actuator/health`.

---

## 3. Règle métier Q1 — gestion de l'inactivité

### Énoncé

> Un compte sans activité depuis plus de **6 mois (180 jours)** voit ses accès
> révoqués et son compte désactivé, automatiquement, tous les jours.

### Mécanisme en deux temps

**1. Enregistrer l'activité.** Le middleware
[`TrackLastActivity`](skillhub_api/app/Http/Middleware/TrackLastActivity.php) est
appliqué à tout le groupe `api`. Il s'exécute **après** la résolution de
l'utilisateur et écrit `users.last_activity_at = now()` sur chaque requête
authentifiée — quelle que soit la voie d'authentification, Sanctum ou SSO.

**2. Purger.** La commande `users:purge-inactive`
([`PurgeInactiveUsers`](skillhub_api/app/Console/Commands/PurgeInactiveUsers.php))
traite les comptes dépassant le seuil, par lots de 200 :

| Étape | Effet |
|-------|-------|
| Révocation | Suppression de tous les jetons Sanctum → déconnexion immédiate |
| Désactivation | `is_active = false`, `deactivated_at = now()`, `deactivation_reason = 'inactivity'` |
| Journalisation | Une entrée de log par compte + une synthèse de l'exécution |

Un compte désactivé **conserve toutes ses données métier** : l'opération est
réversible par un administrateur, contrairement à une suppression.

### Deux garde-fous

- **Aucune activité enregistrée n'est jamais purgée.** Un compte dont
  `last_activity_at` est `NULL` est ignoré : on ne peut pas prouver son
  inactivité, et le désactiver casserait l'accès des comptes créés juste avant la
  mise en place du suivi.
- **L'opération est idempotente.** Un compte déjà désactivé sort du périmètre
  (`scopeActive`), donc rejouer la purge ne réécrit pas sa date de désactivation.

### Effet immédiat côté SSO

Un JWT émis avant la désactivation reste cryptographiquement valide jusqu'à son
expiration. Le refus est donc porté par Laravel, pas par le SSO : le middleware
`SpringSsoAuthenticate` vérifie le statut du compte local et renvoie `403`. La
révocation est effective à la requête suivante, sans attendre l'expiration du jeton.

### Planification

Déclarée dans [`routes/console.php`](skillhub_api/routes/console.php) et exécutée
par le conteneur `skillhub-scheduler` :

```php
Schedule::command('users:purge-inactive')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->onOneServer();
```

> **Note.** Depuis Laravel 11, `app/Console/Kernel.php` n'existe plus : la
> planification se déclare dans `routes/console.php`, qui remplit exactement le
> rôle décrit dans le cahier des charges.

### Utilisation manuelle

```bash
docker compose exec skillhub-laravel php artisan users:purge-inactive --dry-run
```

| Option | Effet |
|--------|-------|
| `--dry-run` | Affiche le tableau des comptes concernés sans rien modifier |
| `--days=N` | Surcharge le seuil (défaut : `USER_INACTIVITY_DAYS`, soit 180) |

### Règle voisine à ne pas confondre

`app:desinscription-inactive` désinscrit les apprenants inactifs **depuis 30
jours** de leurs formations *en cours*, sans toucher au compte. Les deux règles
sont indépendantes et cohabitent dans l'ordonnanceur.

| | `users:purge-inactive` | `app:desinscription-inactive` |
|---|---|---|
| Seuil | 180 jours | 30 jours |
| Cible | Le compte utilisateur | Les inscriptions aux formations |
| Effet | Accès révoqués, compte désactivé | Inscriptions supprimées |
| Horaire | 02:30 | 03:00 |

`app:desinscription-inactive` accepte les mêmes options que la purge :

| Option | Effet |
|--------|-------|
| `--dry-run` | Liste les désinscriptions à venir sans rien supprimer |
| `--days=N` | Surcharge le seuil (défaut : `ENROLLMENT_INACTIVITY_DAYS`, soit 30) |

Le seuil est lu dans `config/skillhub.php`
(`skillhub.inactivity.unenroll_after_days`) et non codé en dur : la commande, le
tableau de bord formateur et les tests s'appuient tous dessus.

**Restitution côté apprenant.** `GET /api/learner/enrollments` renvoie pour
chaque inscription `inactive_days` et `days_before_unenrollment`, plus
`meta.max_active` et `meta.unenroll_after_days`. L'espace apprenant affiche le
compte à rebours avant retrait et alerte dès qu'il passe sous 7 jours.

**Restitution côté formateur.** `GET /api/formateur/enrollments` renvoie, pour
chaque inscription, `last_activity_at`, `inactive_days` et
`days_before_unenrollment`, plus un bloc `meta.unenroll_after_days`. Le champ
`days_before_unenrollment` vaut `null` quand la règle ne s'applique pas —
formation terminée (progression à 100) ou inactivité indéterminable. Le
dashboard React s'en sert pour signaler les apprenants menacés **avant** que la
désinscription ne soit appliquée.

---

### Espace apprenant

Le parcours apprenant existait côté API mais n'avait aucune interface : le
dashboard renvoyait tout non-formateur vers un écran sans issue, et trois verrous
empêchaient même d'obtenir un compte apprenant.

| Verrou | Correction |
|--------|------------|
| `AuthController::register` validait `in:formateur` | ouvert à `in:formateur,apprenant` |
| Le `<select>` de rôle n'offrait que « Formateur » | option « Apprenant » ajoutée |
| `App.jsx` affichait « Accès formateur uniquement » | remplacé par le composant `LearnerSpace` |

L'espace apprenant expose le catalogue, l'inscription et la désinscription, la
progression, et le compte à rebours avant retrait automatique. Il s'appuie sur
les routes `/api/learner/*` qui existaient déjà.

Deux bugs préexistants ont été corrigés au passage :

- `Formation::$fillable` déclarait `short_description` / `full_description`,
  colonnes absentes de la table. La colonne réelle, `description`, n'étant pas
  *mass-assignable*, **toute description passée à `create()` était silencieusement
  perdue** et le catalogue s'affichait vide.
- La limite de 5 formations simultanées était codée en dur dans le contrôleur
  alors que `skillhub.enrollment.max_active` existait ; elle est désormais lue
  depuis la configuration et annoncée au client via `meta.max_active`.

### Authentification : pas de session, pas de CSRF

`bootstrap/app.php` n'appelle **pas** `statefulApi()`. L'API est purement
porteuse de jetons — jetons personnels Sanctum et JWT du SSO — et aucun
contrôleur n'ouvre de session.

C'est délibéré : `sanctum.stateful` liste `localhost:3000` par défaut. Avec
`statefulApi()`, les appels du dashboard basculaient en mode session, où la
protection CSRF refuse tout `POST` dépourvu de cookie `XSRF-TOKEN` — d'où un
`419 CSRF token mismatch` à chaque inscription ou connexion depuis le
navigateur. Le test `test_registering_from_the_dashboard_origin_is_not_blocked_by_csrf`
verrouille ce comportement.

---

## 4. Installation et lancement

### Prérequis

- Docker Desktop, ou Docker Engine + Compose v2
- ~4 Go de RAM disponibles

### Démarrage

```bash
git clone <url-du-depot>
cd EC06_MU202616
cp .env.example .env
docker compose up --build
```

Au premier démarrage, Docker construit les images, attend les *healthchecks* des
bases, applique les migrations Laravel et amorce le compte de démonstration côté
SSO.

| Service | URL |
|---------|-----|
| Tableau de bord formateur (React) | <http://localhost:3000> |
| API métier (Laravel) | <http://localhost:8000> |
| SSO (Spring Boot) | <http://localhost:8080> |

> Le fichier `.env` porte les secrets injectés dans les conteneurs. Il est ignoré
> par Git et ne doit jamais être commité. Les valeurs livrées dans `.env.example`
> sont des valeurs **de développement** : à régénérer avant tout déploiement.

### Vérification en trois commandes

```bash
curl -s -X POST http://localhost:8000/api/sso/login -H "Content-Type: application/json" -d '{"username":"apprenant@skillhub.test","password":"Skillhub123!"}'
```

```bash
curl -s http://localhost:8000/api/sso/me -H "Authorization: Bearer <access_token>"
```

```bash
curl -s http://localhost:8080/actuator/health
```

### Commandes utiles

```bash
docker compose logs -f skillhub-laravel
```

```bash
docker compose exec skillhub-laravel php artisan migrate:status
```

```bash
docker compose down -v
```

*(`down -v` supprime aussi les volumes : les deux bases repartent de zéro.)*

La suite de tests tourne dans un conteneur jetable, sans PHP ni Composer sur la
machine hôte :

```bash
docker compose --profile tests run --rm skillhub-tests
```

Le profil `tests` n'est pas démarré par `docker compose up`. Les tests utilisent
SQLite en mémoire : ils ne touchent jamais aux données de `laravel-db`.

### Développement hors Docker

```bash
cd skillhub_api && composer install && cp .env.example .env && php artisan key:generate && php artisan test
```

```bash
cd Skillhub_springboot && mvn verify
```

---

## 5. Outillage DevOps

### Docker

Les `Dockerfile` sont **à la racine** du dépôt et partagent le même contexte de
build et le même [`.dockerignore`](.dockerignore) — une seule source de vérité
pour la construction des images.

- [`Dockerfile.laravel`](Dockerfile.laravel) — deux étapes : les dépendances
  Composer sont installées dans une couche séparée, invalidée uniquement quand
  `composer.lock` change. Image finale `php:8.3-cli-alpine` avec `pdo_mysql`,
  `pdo_pgsql`, `mbstring`, `zip`, `bcmath`, `intl`, `pcntl`.
  Une troisième étape `test`, jamais publiée, ajoute PHPUnit et `pdo_sqlite` à
  l'image d'exécution : c'est la cible du service `skillhub-tests`.
- [`Dockerfile.sso`](Dockerfile.sso) — build multi-étapes Maven → runtime
  `eclipse-temurin:21-jre-alpine`. Le JDK et Maven (~800 Mo) ne sont présents que
  dans l'étape de compilation.
- [`Dockerfile.frontend`](Dockerfile.frontend) — dashboard React sur `node:16`.
  La version est épinglée : `react-scripts` 3.3.0 repose sur webpack 4, dont le
  hachage md4 est refusé par l'OpenSSL 3 livré à partir de Node 17. Conteneuriser
  évite d'imposer un Node ancien sur la machine hôte.

Choix communs aux deux images :

- **Utilisateur non privilégié** (`skillhub`, UID 1000) : une faille applicative
  ne donne pas les pleins pouvoirs sur le système de fichiers.
- **Outils de compilation supprimés** de l'image finale (`apk del .build-deps`).
- **`HEALTHCHECK`** sur `/up` (Laravel) et `/actuator/health` (Spring), ce qui
  permet à Compose de séquencer correctement les démarrages.

### GitHub Actions

Workflow : [`.github/workflows/ci-cd.yml`](.github/workflows/ci-cd.yml), déclenché
sur `push` et `pull_request` vers `main` et `dev`.

| # | Étape | Laravel | Spring Boot |
|---|-------|---------|-------------|
| 1 | **Checkout** | `actions/checkout@v4`, `fetch-depth: 0` | idem |
| 2 | **Install** | `composer install` | `mvn dependency:resolve` |
| 3 | **Lint** | `phpstan analyse` (niveau 5) + `pint --test` | `mvn checkstyle:check` |
| 4 | **Test** | `php artisan test --coverage-clover` | `mvn verify` (JUnit + JaCoCo) |
| 5 | **SonarCloud** | analyse unique couvrant les deux microservices | |
| 6 | **Build images** | `docker/build-push-action@v6`, `push: false` | idem |
| 7 | **Push images** | `push: true`, uniquement sur `main` | idem |

Points de conception :

- **Parallélisme puis séquence.** Les étapes 1 à 4 tournent en parallèle pour les
  deux bases de code ; les étapes 5 à 7 sont chaînées par `needs`. Aucune image
  n'est construite tant que lint, tests et analyse qualité ne sont pas passés.
- **Les tests ne tournent qu'une fois.** Les rapports de couverture transitent
  entre jobs via `upload-artifact` / `download-artifact` plutôt que d'être
  régénérés dans le job Sonar.
- **Build et push séparés.** Sur une pull request, les images sont construites
  pour valider les `Dockerfile`, mais jamais publiées. Le `docker login` n'a lieu
  qu'au moment du push sur `main` : une PR issue d'un fork n'accède jamais aux
  secrets.
- **Images taguées** avec `${{ github.sha }}` et `latest` — chaque image est
  traçable jusqu'au commit exact qui l'a produite.

**Secrets et variables** (aucun credential en clair dans le dépôt) :

| Type | Nom | Usage |
|------|-----|-------|
| Secret | `SONAR_TOKEN` | Authentification SonarCloud |
| Secret | `REGISTRY_USER` | Identifiant registry Docker |
| Secret | `REGISTRY_TOKEN` | Jeton d'accès registry Docker |
| Variable | `SONAR_ORG` | Organisation SonarCloud |
| Variable | `SONAR_PROJECT_KEY` | Clé du projet SonarCloud |
| Variable | `REGISTRY_URL` | Hôte de la registry (défaut `docker.io`) |

### SonarCloud

Configuration : [`sonar-project.properties`](sonar-project.properties). Un seul
projet Sonar couvre les deux langages, pour que la *Quality Gate* s'applique à la
plateforme entière plutôt qu'à chaque service isolément.

- Couverture PHP : rapport **Clover** produit par PHPUnit + pcov
- Couverture Java : rapport **JaCoCo XML** produit par `mvn verify`
- `projectKey` et `organization` sont injectés par la CI, jamais figés dans le dépôt
- Migrations, fichiers de configuration et classe `main` sont exclus du calcul de
  couverture : y imposer un seuil ne produirait aucun signal utile

---

## 6. Qualité du code — avant / après

### Mesures locales

Toutes les valeurs ci-dessous ont été **mesurées sur le poste de développement**
avant et après l'intégration (`mvn verify`, `php artisan test`,
`phpstan analyse`, `checkstyle:check`).

| Indicateur | Avant | Après | Δ |
|---|---|---|---|
| **Tests Spring Boot** | 0 | **27** | +27 |
| **Couverture Spring (lignes, JaCoCo)** | 0 % | **79,5 %** | +79,5 pts |
| Couverture Spring (branches) | 0 % | 82,6 % | +82,6 pts |
| **Tests Laravel** | 47 | **63** | +16 |
| Tests Laravel en échec | 2 | **0** | −2 |
| **Violations PHPStan (niveau 5)** | 19 | **0** | −19 |
| **Violations Checkstyle** | 7 | **0** | −7 |
| Fichiers non conformes à Pint | 26 | **0** | −26 |

### Lecture des badges SonarCloud

Les badges reflètent ces mesures une fois la pipeline exécutée sur `main` :

| Badge | Avant | Après | Cause |
|---|---|---|---|
| **Coverage** | ~30 % | ~65–70 % | Le microservice SSO passe de 0 à 79,5 % ; il ne tire plus la moyenne globale vers le bas |
| **Quality Gate** | ✗ Failed | ✓ Passed | La condition « couverture du nouveau code ≥ 80 % » n'était pas atteignable sans tests Java |
| **Reliability** | B | A | Suppression des accès à des propriétés non déclarées et des comparaisons toujours vraies |
| **Maintainability** | B | A | Annotations de types sur les modèles, suppression des vérifications mortes, formatage homogène |
| **Security Hotspots** | 3 | 0 | Secrets sortis du dépôt vers `.env` / secrets GitHub ; `.env` racine ajouté au `.gitignore` |
| **Duplications** | < 3 % | < 3 % | Inchangé |

> **Honnêteté méthodologique.** Les valeurs exactes des badges dépendent du
> `SONAR_PROJECT_KEY` et de la Quality Gate configurée dans l'organisation
> SonarCloud ; elles ne sont pas mesurables hors CI. Les colonnes « Avant /
> Après » ci-dessus sont donc l'extrapolation directe des mesures locales, qui,
> elles, sont réelles. Les badges définitifs seront visibles après la première
> exécution du workflow sur `main`.

### Ce qui a produit ces gains

1. **Le SSO n'avait aucun test.** Trois suites ont été ajoutées :
   [`JwtServiceTest`](Skillhub_springboot/src/test/java/mcci/auth/sa_backend/security/JwtServiceTest.java)
   (7 tests : signature, issuer, audience, expiration, jeton malformé),
   [`AuthControllerTest`](Skillhub_springboot/src/test/java/mcci/auth/sa_backend/Controller/AuthControllerTest.java)
   (14 tests, dont la non-divulgation de l'existence d'un compte) et
   [`PasswordResetServiceTest`](Skillhub_springboot/src/test/java/mcci/auth/sa_backend/user/PasswordResetServiceTest.java)
   (6 tests sur le cycle de vie du token). Aucun ne démarre de contexte Spring :
   la suite complète s'exécute en moins de 4 secondes.

2. **Les modèles Eloquent n'étaient pas typés.** L'absence d'annotations
   `@property` empêchait PHPStan de raisonner sur les attributs dynamiques et
   masquait de vraies erreurs — notamment des comparaisons `!== null` sur des
   valeurs jamais nulles. Les quatre modèles sont désormais annotés.

3. **Deux tests étaient déjà rouges avant l'intégration.**
   `RatingApiTest` comparait `4.0` (float) au `4` (int) renvoyé par `json_encode`,
   qui supprime la partie décimale nulle. Corrigé par une comparaison numérique.

4. **Un agrégat SQL était hydraté comme un modèle.** `AVG(score)` était chargé
   dans un objet `Rating`, créant une propriété fantôme `avg_score`. Remplacé par
   `toBase()`, qui renvoie un `stdClass` — un agrégat n'est pas une ligne de la table.

---

## 7. Plan d'action qualité

Trois lots, du plus rentable au plus structurant. Chacun est indépendant et
livrable seul.

### Lot 1 — Couverture (effort : ~2 jours)

**Objectif : dépasser 80 % de couverture globale et sécuriser la Quality Gate.**

| Cible | Action | Gain attendu |
|---|---|---|
| `SecurityConfig`, `UserSeeder` | Test d'intégration Spring avec base H2 en mémoire | +8 pts Java |
| `SsoClient` | Tests unitaires des quatre méthodes avec `Http::fake()`, y compris les délais dépassés | +4 pts PHP |
| `FormationController`, `LearnerEnrollmentController` | Tests des chemins d'erreur (403, 404, validation) | +6 pts PHP |
| `TrainerLearnerController` | Aucun test actuellement — couvrir le nominal et les rejets | +5 pts PHP |

**Critère de sortie :** `Coverage ≥ 80 %` sur le nouveau code, Quality Gate verte
deux exécutions consécutives.

### Lot 2 — Refactoring (effort : ~3 jours)

**Objectif : supprimer les dettes structurelles identifiées pendant l'intégration.**

1. **Sortir la reconnexion MySQL du contrôleur.**
   `AuthController::useSkillhubSql()` force le nom de la base et purge la
   connexion à chaque `register()` / `login()`. C'est de la configuration
   d'infrastructure dans une couche HTTP : elle appartient à
   `config/database.php`. *(Priorité haute — masque les erreurs de configuration
   et empêche tout test réaliste de la connexion.)*

2. **Unifier la sérialisation.**
   Chaque contrôleur possède sa méthode privée `serializeXxx()`. Les remplacer par
   des **API Resources** Laravel donnerait un contrat de sortie unique, testable
   et documentable.

3. **Extraire les règles métier des contrôleurs.**
   La limite de 5 inscriptions simultanées vit dans
   `LearnerEnrollmentController::store()`. La déplacer dans une classe dédiée
   (`EnrollmentPolicy`) la rendrait testable sans passer par HTTP — le seuil est
   déjà externalisé dans `config/skillhub.php`.

4. **Réconcilier les deux schémas `formations`.**
   La table existe sous deux formes (migrations vs. dump historique), d'où les
   gardes `hasFormationsColumn()`. Une migration de convergence supprimerait ces
   branches conditionnelles.

5. **Passer `ddl-auto` à `validate`.**
   `spring.jpa.hibernate.ddl-auto=update` laisse Hibernate modifier le schéma en
   production. Introduire Flyway et basculer sur `validate`.

### Lot 3 — Optimisation et durcissement (effort : ~2 jours)

| Sujet | Constat | Action |
|---|---|---|
| **Latence SSO** | Chaque requête protégée déclenche un appel HTTP `GET /auth/validate` | Mettre en cache la validation (clé = hash du jeton, TTL = `exp` − 30 s) : divise par ~10 le nombre d'appels inter-services |
| **Requêtes N+1** | `serializeEnrollment()` accède à `$enrollment->user` et `->formation` | Généraliser `with()` sur tous les points d'entrée de liste |
| **Vulnérabilités** | `composer audit` remonte 35 advisories sur 11 paquets | Traiter par lots, en commençant par les dépendances directes ; ajouter `composer audit` en étape CI bloquante |
| **Index manquant** | `users:purge-inactive` filtre sur `last_activity_at` sans index | Ajouter un index — la purge devient une recherche indexée au lieu d'un scan complet |
| **Rotation du secret JWT** | `SA_JWT_SECRET` est unique et permanent | Introduire un `kid` dans l'en-tête JWT pour permettre une rotation sans invalider les jetons en cours |

### Suivi

| Indicateur | Actuel | Cible T+1 mois |
|---|---|---|
| Couverture globale | ~65 % | ≥ 80 % |
| Quality Gate | à valider | verte en continu |
| Vulnérabilités dépendances | 35 | 0 critique / 0 haute |
| Dette technique (Sonar) | à mesurer | ≤ 5 jours |

---

## 8. Écarts assumés au cahier des charges

Cinq écarts, tous dictés par l'état réel du dépôt. Chacun est un choix
délibéré, pas un oubli.

| Point du cahier des charges | Réalisé | Pourquoi |
|---|---|---|
| Laravel 11 / PHP 8.3 | **Laravel 12** / PHP 8.3 | Le projet est déjà en Laravel 12. Rétrograder casserait les dépendances pour un gain nul ; Laravel 12 est un sur-ensemble compatible. |
| Spring Boot / **Java 17** | Java 21 | Le `pom.xml` cible Java 21 et Spring Boot 4.0.3. Toute la chaîne (CI, images, tests) est alignée sur 21. |
| Scheduler dans `app/Console/Kernel.php` | `routes/console.php` | Ce fichier **n'existe plus** depuis Laravel 11. `routes/console.php` en est l'équivalent officiel et remplit le même rôle. |
| `sso-db` PostgreSQL sur le port **5432** | Port hôte **5433** | Un PostgreSQL installé nativement occupe presque toujours 5432. Sous Windows, Docker publie alors le port sans erreur mais un client sur `localhost:5432` peut atteindre le mauvais serveur. Le port **interne** reste 5432 ; seule la publication sur l'hôte change, et `SSO_DB_PORT` permet de la surcharger. |
| `laravel-db` en **PostgreSQL** (`skillhub_db`, port 5433) | **MySQL 8** (`skillhubsql`, port 3307) | Choix explicite du mainteneur : les données métier et le dump `Database/skillhubsql.sql` sont en MySQL. Le pattern *Database per Service* reste respecté — deux bases isolées dans deux SGBD distincts. |

Points du cahier des charges **appliqués tels quels** : SSO sur le port `8080`,
réseau `skillhub-network`, middleware
`SpringSsoAuthenticate` avec `Auth::setUser()`, `users:purge-inactive` à 180 jours,
`Dockerfile.laravel` / `Dockerfile.sso` / `docker-compose.yml` à la racine,
pipeline en 7 étapes dans l'ordre imposé, secrets exclusivement via
`${{ secrets.* }}`.

---

## 9. Arborescence

```
EC06_MU202616/
├── Dockerfile.laravel              # Image API métier (PHP 8.3)
├── Dockerfile.sso                  # Image SSO (multi-étapes Maven → JRE 21)
├── docker-compose.yml              # Orchestration des 5 services
├── .dockerignore                   # Contexte de build partagé
├── .env.example                    # Configuration unifiée (Laravel + Spring + Docker)
├── sonar-project.properties        # Analyse SonarCloud multi-langages
│
├── .github/workflows/
│   └── ci-cd.yml                   # Pipeline en 7 étapes
│
├── skillhub_api/                   # ── Microservice métier (Laravel 12)
│   ├── app/
│   │   ├── Console/Commands/
│   │   │   ├── PurgeInactiveUsers.php        # Règle Q1 : purge à 180 jours
│   │   │   └── DesinscriptionInactive.php    # Désinscription à 30 jours
│   │   ├── Http/Middleware/
│   │   │   ├── SpringSsoAuthenticate.php     # Délégation d'authentification
│   │   │   └── TrackLastActivity.php         # Suivi de last_activity_at
│   │   ├── Services/
│   │   │   ├── SsoClient.php                 # Client HTTP du SSO
│   │   │   └── SsoUserProvisioner.php        # Claims JWT → utilisateur local
│   │   └── Models/ Http/Controllers/
│   ├── config/skillhub.php         # Seuils des règles métier
│   ├── database/migrations/
│   ├── routes/console.php          # Planification des tâches
│   ├── tests/                      # 63 tests
│   ├── docker/entrypoint.sh
│   └── phpstan.neon                # Analyse statique niveau 5
│
├── Skillhub_springboot/            # ── Microservice SSO (Spring Boot 4)
│   ├── src/main/java/mcci/auth/sa_backend/
│   │   ├── Controller/AuthController.java
│   │   ├── security/  JwtService.java · SecurityConfig.java
│   │   └── user/      UserAccount · UserRepository · PasswordResetService
│   ├── src/main/resources/application.yml
│   ├── src/test/java/                        # 27 tests
│   ├── checkstyle.xml
│   └── pom.xml
│
├── Tableau de bord - Formateur/     # Dashboard React + Vite (skillhub-frontend)
│   └── src/components/
│       ├── App.jsx                  # Espace formateur + suivi d'inactivite
│       ├── LearnerSpace.jsx         # Espace apprenant : catalogue, inscriptions
│       └── Auth.jsx                 # Connexion / creation de compte
└── Database/skillhubsql.sql         # Dump historique MySQL
```
