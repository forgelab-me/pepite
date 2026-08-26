# Pépite

[🇬🇧 English version](README.md)

[![Tests](https://github.com/forgelab-me/pepite/actions/workflows/tests.yml/badge.svg)](https://github.com/forgelab-me/pepite/actions/workflows/tests.yml)
[![Docker image](https://github.com/forgelab-me/pepite/actions/workflows/docker.yml/badge.svg)](https://github.com/forgelab-me/pepite/actions/workflows/docker.yml)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

Serveur NuGet (protocole V3) en PHP / CodeIgniter 4. Les implémentations existantes (BaGet,
LiGet, NuGet.Server, ProGet) exigent toutes .NET et un hébergement dédié — Pépite vise le cas
inverse : un mutualisé à quelques euros par mois, sans Docker, sans démon, sans SSH obligatoire.
Une image Docker existe aussi, pour qui préfère ça.

- **Protocole V3 complet** — service index, flat container, registration (V2 et SemVer 2),
  search, autocomplete. `dotnet restore`, `dotnet nuget push`, Visual Studio et Rider s'y
  connectent sans configuration particulière.
- **Feeds multiples**, publics ou privés, chacun avec ses propres identifiants de package
  acceptés (`packageType`) — de quoi exposer un catalogue applicatif séparé des libs .NET
  classiques sans dupliquer le serveur.
- **Clés API à portée restreinte** — par feed, par motif d'identifiant (`Contoso.*`), avec ou
  sans droit de créer de nouveaux identifiants. Propriété au premier push.
- **Trusted Publishing** — un job GitHub Actions ou GitLab CI/CD peut pousser sans jamais détenir
  de clé longue durée, en échangeant sa propre identité OIDC contre une clé à portée restreinte et
  durée de vie courte au moment du push. Le même mécanisme que npm, PyPI et nuget.org.
- **Délistage, jamais suppression** — un client qui dépend déjà d'une version délistée continue
  de la restaurer normalement ; seule sa visibilité en recherche change.
- **Console d'admin** — feeds, clés API, navigation et modération des packages (délister /
  relister), le tout en web, sans ligne de commande.
- **Installateur web** — vérifie les prérequis, prend la connexion base, migre, crée le premier
  compte administrateur, se verrouille après usage.
- **Auto-mise à jour** ([`ci4-updater`](https://github.com/forgelab-me/ci4-updater)) — panneau
  `/admin/updates` : vérifie, télécharge, diffe, applique, avec sauvegarde et migrations
  automatiques. Le mécanisme visé en premier lieu par ce projet : un mutualisé sans SSH.

## Docker

```bash
curl -O https://raw.githubusercontent.com/forgelab-me/pepite/main/compose.yaml
curl -o .env https://raw.githubusercontent.com/forgelab-me/pepite/main/.env.example
# éditer .env, puis :
docker compose up -d
```

Serveur plus MariaDB, depuis `ghcr.io/forgelab-me/pepite` (amd64 et arm64). Le conteneur
attend la base, migre et (re)synchronise le compte administrateur à chaque démarrage — tout est
idempotent.

L'auto-mise à jour est désactivée en pratique dans ce mode : mettre à jour, c'est tirer une
nouvelle image (`docker compose pull && docker compose up -d`), pas cliquer sur le panneau
`/admin/updates`, qui écrirait dans un système de fichiers jeté au prochain déploiement.

Guide complet — configuration, sauvegardes, reverse proxy : **[docs/docker.md](docs/docker.md)**.

## Installation depuis les sources

L'application vit dans `src/` ; la racine du dépôt porte l'empaquetage et la documentation.
Toutes les commandes ci-dessous s'exécutent depuis `src/`.

### Prérequis

- PHP **8.2+** avec `intl`, `mbstring`, `zip`, `dom`
- SQLite (développement) ou MySQL / MariaDB (production)
- HTTPS en production — le SDK .NET refuse les sources HTTP

### Développement

```bash
git clone https://github.com/forgelab-me/pepite.git
cd pepite/src
composer install
cp env .env          # puis régler CI_ENVIRONMENT, app.baseURL et la base
php spark migrate --all
php spark db:seed DevAdminSeeder
php spark serve
```

L'administrateur de développement est créé avec `admin@pepite.test` / `pepite-dev-2026`, ou les
valeurs des variables d'environnement `PEPITE_DEV_ADMIN_EMAIL` et `PEPITE_DEV_ADMIN_PASSWORD`.
Ce compte n'existe qu'en développement (`DevAdminSeeder` refuse de tourner en production) : en
production c'est l'installateur web, ou `AdminUserSeeder` dans le cas de l'image Docker, qui
crée le premier compte.

### Vérifications

```bash
composer test
```

```bash
composer cs
```

`composer cs:fix` applique les corrections de style. Le standard est celui de CodeIgniter 4.

Le développement tourne sur SQLite et la production sur MySQL/MariaDB ; les deux moteurs
divergent sur un point qui compte (la collation de `version_sort_key`), donc la CI fait tourner
la suite sur les deux — voir [`tools/test-mysql.sh`](tools/test-mysql.sh) pour la reproduire en
local.

## Repères

| Chemin | Rôle |
|---|---|
| `src/app/Libraries/Version/` | Versions et plages NuGet, clé de tri |
| `src/app/Libraries/Package/` | Lecture de `.nupkg`, parsing de `.nuspec` |
| `src/app/Libraries/Http/` | Parsing du `PUT` multipart en flux |
| `src/app/Controllers/V3/` | Protocole NuGet V3 |
| `src/app/Controllers/Api/` | Publication, délistage |
| `src/app/Controllers/Admin/` | Console d'admin — feeds, clés, packages |
| `src/writable/storage/` | Blobs des packages, hors racine web |

Les classes de `Libraries/Version`, `Libraries/Package` et `Libraries/Http` n'appellent ni
`service()`, ni `config()`, ni `db_connect()`, ni `request()` : ce sont des classes PHP
ordinaires, construites par leur constructeur et testées sans booter le framework.

Les routes du protocole vivent sous `/feeds/{slug}/…` et n'héritent jamais des filtres de
session ou CSRF : un client en ligne de commande n'a ni cookie ni jeton.

## Déploiement sur un mutualisé (OVH et équivalents)

1. Générer une release : `php spark update:manifest` (depuis `src/`). Le ZIP produit inclut
   `vendor/` — il n'y a pas de Composer sur un mutualisé.
2. Dézipper à la racine du compte, avec la **racine web pointée sur `public/`**.
3. Ouvrir `https://votredomaine/install` : le programme vérifie les extensions PHP, prend la
   connexion base, exécute les migrations, crée le compte administrateur et écrit `.env`. Il se
   verrouille ensuite (`writable/install.lock`).
4. `public/.user.ini` relève les limites d'upload par défaut ; certains hébergeurs demandent
   quelques minutes avant de le prendre en compte.
5. Mises à jour ultérieures : panneau `/admin/updates`, ou `php spark updater:check` /
   `updater:apply` en SSH. Dans les deux cas, le serveur se met lui-même en maintenance le temps
   exact de l'écriture — chaque client, NuGet ou navigateur, reçoit un `503` propre plutôt que de
   heurter des fichiers en cours de remplacement — et en ressort tout seul. Rien à basculer à la
   main.

`writable/` et `.env` doivent être accessibles en écriture par PHP. `writable/storage/` (les
blobs de packages) doit rester hors de la racine web.

**Si un CDN/WAF (Cloudflare notamment) est devant l'instance** : ses protections anti-bot
bloquent souvent `curl`, `dotnet` et `nuget.exe` alors qu'un navigateur passe — même sur un
simple `GET`. Test rapide une fois déployé :

```bash
curl -i https://votredomaine/feeds/default/v3/index.json
```

Un `403` générique (pas la réponse JSON de Pépite) alors que la même URL fonctionne au
navigateur signifie que le CDN bloque les clients non-navigateurs. Sur Cloudflare :
**Security → WAF → Custom rules**, règle `URI Path contains /feeds/` → **Skip** : Bot Fight
Mode, Super Bot Fight Mode, Security Level, Browser Integrity Check.

## Trusted Publishing

Un job GitHub Actions ou GitLab CI/CD peut pousser des packages sans aucune clé API stockée dans
les paramètres du dépôt — il échange son propre jeton d'identité OIDC contre une clé NuGet à
portée restreinte, valable 15 minutes, au moment du push, optionnellement épinglée à un fichier
de workflow précis. À configurer depuis la page **Publishers** d'un feed dans la console d'admin
(elle-même en anglais), qui affiche directement le YAML à coller pour le provider activé. Guide
complet, notamment comment un *environment* protégé peut placer une validation humaine entre un
push et une publication : **[docs/trusted-publishing.md](docs/trusted-publishing.md)** (anglais).

## Sécurité

- La console d'admin (`/admin/*`) est authentifiée via
  [`codeigniter4/shield`](https://github.com/codeigniter4/shield) — seuls des comptes de
  confiance doivent y avoir accès, puisque créer une clé API ou un feed y donne accès à la
  publication.
- Une clé API sans restriction (`feed_api_key_rules`) peut publier sur n'importe quel feed
  autorisant les nouveaux identifiants. Restreindre une clé à un feed et à un motif
  d'identifiant (`Contoso.*`) dès qu'elle sort d'un usage strictement personnel.
- `.env` est exclu du dépôt — n'y committez jamais de secrets réels. Seul `env` (le gabarit
  CI4) est suivi.
- La clé privée de signature des releases (`php spark updater:keygen`) ne doit **jamais**
  quitter la machine qui construit les releases ; seule sa moitié publique va dans
  `Config\Updater::$publicKeys`.

## Historique des versions

Voir [CHANGELOG.md](CHANGELOG.md) (anglais) — écrit pour qui exploite ce serveur, avec la
marche à suivre en cas de mise à jour.

## Licence

MIT — voir [LICENSE](LICENSE).

NuGet est une marque de la .NET Foundation. Pépite est un projet indépendant, sans lien avec
Microsoft ni la .NET Foundation.
