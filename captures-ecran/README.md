# Captures d'écran et preuves d'exécution

Éléments de preuve collectés le **20/08/2026** pour la fonctionnalité de
désinscription automatique des apprenants inactifs.

Les fichiers `.log` sont la **sortie brute et non retouchée** des commandes
indiquées en tête de chaque fichier. Les secrets (JWT, mots de passe) y sont
tronqués.

| Fichier | Contenu | État |
|---------|---------|------|
| `01-docker-compose-up.log` | `docker compose up -d` + `docker compose ps` — 5 services `healthy` | ✅ |
| `02-github-actions-echec-facturation.png` | Page GitHub Actions du dépôt | ⛔ voir ci-dessous |
| `04-authentification-sso.log` | Login SSO Spring Boot, route protégée, `actuator/health` | ✅ |
| `05-commande-desinscription.log` | `app:desinscription-inactive --dry-run` + `schedule:list` | ✅ |

## ⛔ Pipeline GitHub Actions : non capturable en vert

Les 4 exécutions du dépôt échouent en 3–4 secondes, **avant même de démarrer les
jobs**. L'annotation GitHub est sans ambiguïté (visible sur la capture) :

> The job was not started because your account is locked due to a billing issue.

Le blocage est **administratif, pas technique** : aucune modification du code ou
du workflow ne peut le lever. Il faut régulariser la facturation GitHub Actions
sur le compte `dayandaniel30`, après quoi le pipeline pourra s'exécuter.

## ⛔ Rapport SonarCloud : non généré

Deux raisons cumulées :

1. Le job `sonarcloud` du workflow dépend de `laravel-ci` et `spring-ci`, qui ne
   démarrent pas (voir ci-dessus).
2. Le dépôt ne définit **ni les variables** `SONAR_ORG` / `SONAR_PROJECT_KEY`,
   **ni le secret** `SONAR_TOKEN` qu'attend `ci-cd.yml`
   (`gh variable list` et `gh secret list` renvoient une liste vide).

Aucune analyse n'a donc jamais été poussée vers SonarCloud : il n'existe pas de
rapport à capturer. Il faut d'abord créer le projet sur sonarcloud.io puis
renseigner ces trois valeurs dans les paramètres du dépôt.

## ⛔ Tableau de bord React : dépendances non installables

`npm install` échoue — sur la machine hôte comme dans le conteneur — parce que le
pare-feu **Fortinet (`FG120GTK25047293`)** intercepte le TLS et répond
**HTTP 403** sur `registry.npmjs.org` :

```
Sujet    : CN=npmjs.org
Émetteur : E=support@fortinet.com, CN=FG120GTK25047293, O=Fortinet, ...
```

`github.com`, `packagist.org` et `repo.maven.apache.org` restent joignables :
seul le registre npm est filtré. L'installation hors ligne depuis le cache npm
local (8,7 Go) couvre presque tout l'arbre de dépendances, mais bute sur
`yargs-parser@13.1.1`, absent du cache.

Lever ce point demande une action réseau (autoriser `registry.npmjs.org` dans la
politique Fortinet, ou installer depuis un autre réseau) — pas une modification
du projet.
