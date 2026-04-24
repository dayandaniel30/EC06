# Tableau de bord - Formateur (SkillHub)

Application React (frontend) pour les formateurs SkillHub.

## Fonctionnalites
- Authentification formateur (inscription/connexion) via API Laravel.
- Tableau de bord formateur.
- CRUD des formations (creer, lister, modifier, supprimer).
- Recherche et filtrage des formations par prix.
- Gestion de session via token (`localStorage`).

## Stack
- React 16
- Axios + Fetch API
- CSS custom (`src/components/app.css`)

## Prerequis
- Node.js 18+
- API SkillHub Laravel demarree localement

## Configuration
Fichier: `.env.development`

```env
REACT_APP_API_BASE_URL=http://127.0.0.1:8000
```

## Installation
```bash
npm install
```

## Lancement
```bash
npm start
```
Application disponible sur `http://localhost:3000`.

## Build production
```bash
npm run build
```

## Redirection mise a jour (suite au renommage)
- Le frontend n'utilise plus de route `/dashboard` dediee.
- Le flux d'auth redirige maintenant vers la page racine `/` (single-page dashboard).
- Les anciennes references `react-js-crud` ont ete retirees de la documentation.

## Structure utile
- `src/components/App.js`: dashboard formateur + CRUD formations
- `src/components/Auth.js`: login/inscription
- `src/services/api.js`: client API centralise
- `src/components/app.css`: styles

## API attendue
Endpoints utilises:
- `POST /api/register`
- `POST /api/login`
- `POST /api/logout`
- `GET /api/my-formations`
- `POST /api/formations`
- `PUT /api/formations/{id}`
- `DELETE /api/formations/{id}`

## Note sur l'utilisation de l'IA
Ce projet a utilise l'IA comme outil d'assistance (aide a la structuration, verification et documentation), mais le travail principal a ete realise manuellement: conception, integration, corrections, tests et decisions techniques. L'IA a servi d'appui, tandis que l'effort et la realisation du projet reposent sur l'investissement conçu ainsi que d'autres sources (W3school, openclassroom...).
