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
| `src/app/Controllers/Account/` | Libre-service — clés, packages possédés (tout compte connecté) |
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

## Contributeurs tiers (libre-service)

L'inscription est ouverte par défaut (`Config\Auth::$allowRegistration`) — n'importe quel compte
peut pousser ses propres packages sans qu'un admin lui crée une clé à la main. Sur
`/account/keys`, un compte connecté délivre une clé restreinte à exactement un feed, choisi parmi
ceux qui sont à la fois **publics** et acceptent de nouveaux packages (`allow_new_packages`). Un
feed qui n'accepte pas de nouveaux packages n'offre aucun chemin vers la propriété en
libre-service — ni par ce formulaire, ni autrement — donc il n'apparaît jamais dans la liste.
`/account` liste tous les packages possédés par le compte courant, tous feeds confondus.

Une clé en libre-service ne porte jamais le scope `packages.read`, volontairement : elle peut
pousser et délister, rien de plus. Voir la section Sécurité ci-dessous pour la raison.

**La vérification par email est désactivée par défaut**
(`Config\Auth::$actions['register']` vaut `null`) — Pépite ne configure aucun transport mail par
défaut (`Config\Email`), et forcer la vérification bloquerait silencieusement toute inscription
sur un déploiement sans transport fonctionnel (Docker, la plupart des installations locales,
certains hébergeurs mutualisés) — le compte reste en attente d'activation, l'email de
confirmation n'arrive jamais. Une fois `Config\Email` pointé vers un transport dont vous avez
vérifié qu'il délivre réellement, l'activer :

```php
public array $actions = [
    'register' => \CodeIgniter\Shield\Authentication\Actions\EmailActivator::class,
];
```

L'inscription et la connexion sont déjà limitées en fréquence quoi qu'il arrive (filtre
`AuthRates` de Shield, 10 requêtes/minute/IP) — rien à configurer en plus pour ça.

## Supprimer un package définitivement

Délister masque une version de la recherche et de la restauration, mais ne la supprime jamais —
tout ce qui en dépend déjà continue de fonctionner, volontairement. Cette garantie est
délibérément contournable pour le seul cas où elle ne peut pas tenir : un fichier publié qui
n'aurait jamais dû être public (un secret qui fuite, des données personnelles, un malware). Deux
façons équivalentes de supprimer un package ou une de ses versions — lignes en base et fichier
stocké, de façon irréversible :

- `php spark pepite:purge <feed-slug> <package-id> [version] [--yes]` — affiche d'abord
  précisément ce qui va être supprimé, puis demande de retaper l'identifiant du package pour
  confirmer.
- Depuis la page d'un package dans la console d'admin, réservé à un **superadmin** (voir
  ci-dessous) — la même exigence de confirmation tapée, dans le navigateur.

Aucun des deux n'est accessible à un compte `admin` simple. Créer un superadmin se fait en ligne
de commande (il n'y a pas d'interface pour ça — elle devrait elle-même être réservée aux
superadmins, ce qui ne résout rien pour le tout premier) :

```bash
php spark shield:user addgroup <email> admin
php spark shield:user addgroup <email> superadmin
```

Les deux appartenances de groupe sont nécessaires — le filtre de route de la console d'admin
exige déjà `admin` seul, et les actions de suppression vérifient en plus `superadmin` par-dessus.

## Sécurité

- La console d'admin (`/admin/*`) est authentifiée via
  [`codeigniter4/shield`](https://github.com/codeigniter4/shield) — seuls des comptes de
  confiance doivent y avoir accès, puisque créer une clé API ou un feed y donne accès à la
  publication.
- Une clé API sans restriction (`feed_api_key_rules`) peut publier sur n'importe quel feed
  autorisant les nouveaux identifiants. Restreindre une clé à un feed et à un motif
  d'identifiant (`Contoso.*`) dès qu'elle sort d'un usage strictement personnel. Une clé *avec*
  une restriction est aussi confinée à ne lire que le(s) feed(s) qu'elle nomme —
  `App\Filters\FeedRead` consulte les mêmes règles pour la lecture d'un feed privé, pas
  seulement `PublishAuthorizer` pour la publication.
- Les clés en libre-service (`/account/keys`) sont volontairement restreintes par construction
  — `packages.push` et `packages.unlist` seulement, un seul feed, jamais `packages.read` —
  puisque le libre-service ne porte aucune des vérifications implicites qu'un admin fait déjà en
  créant une clé à la main.
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
