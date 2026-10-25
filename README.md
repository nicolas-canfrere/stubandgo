# stubandgo

API de billetterie en ligne : des organisations publient des événements datés avec plusieurs tarifs et une jauge, un acheteur sans compte commande, paie (paiement simulé) et reçoit ses billets par email avec un QR code ; le scan du QR code à l'entrée « grille » le billet.

La L1 a livré l'API complète du parcours « vendre et contrôler » ; la L2 y ajoute Keycloak : le scan exige le jeton d'accès d'un contrôleur de l'organisation de l'événement, le reste du parcours reste public. Symfony 8.1, PHP 8.4, PostgreSQL (DBAL, sans ORM), RabbitMQ (Messenger), Mailpit.

## Prérequis

Docker avec Docker Compose et `make`. PHP et Composer ne sont pas nécessaires sur la machine : tout passe par des conteneurs.

```bash
make install   # dépendances PHP (Composer dans un conteneur)
```

## Démarrage

```bash
make up                                                          # nginx, php, PostgreSQL, RabbitMQ, Mailpit, worker, Keycloak
docker compose exec php bin/console doctrine:migrations:migrate -n
docker compose exec php bin/console app:demo:load                # 2 organisations, 3 événements (tarifs Plein / Réduit / Gratuit)
```

Keycloak met une trentaine de secondes à démarrer : attendre que `docker compose ps` le montre `healthy` (ou que `make keycloak-check` passe) avant de se connecter.

Une base chargée sous la L1 n'a pas l'identifiant Keycloak de ses organisations (`keycloak_id` vide) : tout scan y répond 403. Recréez-la avec la procédure ci-dessous.

Pour préparer la base de test : `make test-db`.

`app:demo:load` ne charge les données qu'une fois et date les événements de 31 à 60 jours après le chargement : au bout d'un mois, ils sont passés et disparaissent des listes. Pour repartir de données neuves, recréer la base (le worker est arrêté pendant ce temps, car PostgreSQL refuse de supprimer une base encore utilisée) :

```bash
docker compose stop worker
docker compose exec php bin/console doctrine:database:drop --force
docker compose exec php bin/console doctrine:database:create
docker compose exec php bin/console doctrine:migrations:migrate -n
docker compose exec php bin/console app:demo:load
docker compose start worker
```

| Service | URL |
|---|---|
| API | http://localhost:8080 |
| Documentation OpenAPI | http://localhost:8080/api/doc (document committé : `openapi.yaml`) |
| Santé | http://localhost:8080/health |
| Métriques (format Prometheus) | http://localhost:8080/metrics |
| Mailpit (emails reçus) | http://localhost:8025 |
| RabbitMQ (guest / guest) | http://localhost:15672 |
| Keycloak (console `admin` / `admin`) | http://localhost:8180 |

`/health` répond 200 quand la base, la file d'attente, l'outbox et le worker sont sains (le worker doit avoir battu au moins une fois, ce qui prend quelques secondes après `make up`).

## Parcours de démonstration

Les commandes ci-dessous utilisent `curl` et `jq`.

```bash
A=localhost:8080
E=$(curl -s $A/api/events | jq -r '.[0].id')                     # Soirée Rock
D=$(curl -s $A/api/events/$E)
PLEIN=$(echo "$D" | jq -r '.priceTiers[] | select(.label=="Plein").id')
REDUIT=$(echo "$D" | jq -r '.priceTiers[] | select(.label=="Réduit").id')
BODY=$(jq -nc --arg e $E --arg p $PLEIN --arg r $REDUIT \
  '{eventId:$e, buyerEmail:"alice@example.com", lines:[{priceTierId:$p,quantity:1},{priceTierId:$r,quantity:1}]}')

curl -s $A/api/events/$E/availability                            # {"eventId":"…","remaining":200}

# 1. Une commande, puis un paiement refusé : les places sont libérées (402)
O1=$(curl -s -X POST $A/api/orders -H 'Content-Type: application/json' -d "$BODY" | jq -r .id)
curl -s -X POST $A/api/orders/$O1/payment -H 'Content-Type: application/json' -d '{"cardNumber":"4000000000000002"}'
curl -s $A/api/events/$E/availability                            # remaining restauré à 200

# 2. Une nouvelle commande, payée avec la carte acceptée (200, "paid")
O2=$(curl -s -X POST $A/api/orders -H 'Content-Type: application/json' -d "$BODY" | jq -r .id)
curl -s -X POST $A/api/orders/$O2/payment -H 'Content-Type: application/json' -d '{"cardNumber":"4242424242424242"}'
curl -s $A/api/orders/$O2                                        # état de la commande

# 3. L'email part en asynchrone (outbox, relais, RabbitMQ, worker) : quelques secondes plus tard
curl -s http://localhost:8025/api/v1/messages | jq '.messages[] | {Subject, To: [.To[].Address]}'
```

L'email contient un QR code par billet (images `ticket-1.png`, `ticket-2.png`). Le QR code encode le jeton du billet ; pour le démontrer sans lecteur de QR code, on le lit en base, puis on le scanne deux fois.

Le scan exige le jeton d'accès Keycloak d'un **contrôleur de l'organisation de l'événement** (rôle `organization-controller` du realm `stubandgo`, voir [Keycloak](#keycloak)) : pour « Soirée Rock », `controleur.rock`. Le client `stubandgo-app` n'accepte que le flux code + PKCE, il n'y a pas de connexion directe en ligne de commande : le jeton se lit dans le navigateur, après connexion au front (dépôt `stubandgo-app`, http://localhost:5173/scan) avec `controleur.rock` / `stubandgo-demo`. Dans les outils de développement : Application → Session Storage → `http://localhost:5173` → clé `oidc.user:http://localhost:8180/realms/stubandgo:stubandgo-app`, champ `access_token` ; ou, dans l'onglet Réseau, l'en-tête `Authorization: Bearer …` d'un appel de scan. Le jeton expire au bout de 5 minutes (durée par défaut de Keycloak) : en reprendre un frais si le scan répond 401. Puis :

```bash
JWT='<jeton d'accès de controleur.rock>'
TOKEN=$(docker compose exec -T database psql -U app -d app -At -c \
  "SELECT a.token FROM admission_right a JOIN ticket t ON t.id = a.ticket_id WHERE t.order_id = '$O2' ORDER BY t.id LIMIT 1")
curl -s -X POST $A/api/events/$E/scans -H "Authorization: Bearer $JWT" -H 'Content-Type: application/json' -d "{\"token\":\"$TOKEN\"}"
# {"outcome":"valid","firstUsedAt":null}
curl -s -X POST $A/api/events/$E/scans -H "Authorization: Bearer $JWT" -H 'Content-Type: application/json' -d "{\"token\":\"$TOKEN\"}"
# {"outcome":"already_used","firstUsedAt":"2026-…Z"}
```

Sans jeton, le scan répond `401 /problems/unauthorized` ; avec le jeton d'un autre membre (`controleur.theatre`, `admin.rock` qui n'est pas contrôleur, un compte du realm de la plateforme), ou pour un événement inconnu, il répond `403 /problems/forbidden`. Un scan refusé n'est pas enregistré. Un jeton de la plateforme s'obtient en direct avec le client `stubandgo-staff-cli`, mais il ne donne aucun droit en L2 :

```bash
STAFF_JWT=$(curl -s http://localhost:8180/realms/stubandgo-staff/protocol/openid-connect/token \
  -d grant_type=password -d client_id=stubandgo-staff-cli -d username=admin.plateforme -d password=stubandgo-demo | jq -r .access_token)
curl -s -X POST $A/api/events/$E/scans -H "Authorization: Bearer $STAFF_JWT" -H 'Content-Type: application/json' -d "{\"token\":\"$TOKEN\"}"
# {"type":"/problems/forbidden",…,"status":403,…}
```

Cartes de test : `4242424242424242` (acceptée), `4000000000000002` (refusée). Un événement de démonstration, « Concert Intimiste », n'a que 2 places : pratique pour provoquer un `409 sold_out`.

Les erreurs HTTP suivent la RFC 9457 (`application/problem+json`), les dates de l'API sont en UTC (suffixe `Z`).

## Back-office (L3)

Deux espaces, qui exigent un jeton (401 sinon) : la **plateforme** (`/api/platform/…`, realm `stubandgo-staff`, rôle `platform-admin`) et l'**espace organisation** (`/api/backoffice/…`, realm `stubandgo`, rôle `organization-admin`, sur l'organisation Keycloak du membre). Un jeton sans le droit, un événement inconnu ou d'une autre organisation répondent `403 /problems/forbidden`. Le détail de chaque route (schémas, erreurs) est dans `openapi.yaml`.

| Route | Rôle |
|---|---|
| `GET /api/platform/organizations` | liste (nom, slug, taux en points de base, statut), triée par nom |
| `POST /api/platform/organizations` | crée l'organisation (`{"name","slug","commissionBasisPoints"}`) puis la provisionne dans Keycloak ; slug ou nom déjà pris : 409 |
| `POST /api/platform/organizations/{id}/provisioning` | relance le provisionnement (déjà actif : 200 sans appel à Keycloak ; Keycloak en échec : 502) |
| `PUT /api/platform/organizations/{id}/commission-rate` | change le taux (les commandes existantes gardent le taux figé à leur création) |
| `POST /api/platform/organizations/{id}/invitations` | invite un membre (`{"email","role"}`, `admin` ou `controller`) |
| `POST /api/backoffice/invitations` | idem, pour l'organisation de l'administrateur |
| `GET` / `POST /api/backoffice/events` | liste (tous statuts) / création en `draft` |
| `GET` / `PUT /api/backoffice/events/{eventId}` | détail (brouillon compris) / remplacement du titre, de la description, du lieu, de la date, de la capacité et de la liste complète des tarifs |
| `POST` / `DELETE /api/backoffice/events/{eventId}/publication` | publier / dépublier |
| `GET /api/backoffice/events/{eventId}/sales` | billets et montants par tarif, total, commission, net dû, places restantes |

Règles : un événement ne se modifie qu'en `draft` et non verrouillé (dépublier d'abord) ; il est verrouillé dès la première place prise, et ni sa modification ni sa dépublication ne sont alors possibles (409 `/problems/event_locked`) ; la date de début doit être dans le futur. Les ventes ne comptent que les commandes payées, aux montants et au taux figés.

### Démo pas à pas

Dans l'ordre : `make keycloak-reset` si le volume Keycloak a déjà servi, `make up`, migrations, `app:demo:load` (voir [Démarrage](#démarrage)). Les clients des realms sont des clients publics code + PKCE : le jeton se lit dans le front (dépôt `stubandgo-app`), ou, pour le realm de la plateforme seulement, s'obtient en direct avec `stubandgo-staff-cli` comme plus haut.

1. `admin.plateforme` crée « Jazz Club » avec un taux de 3 % (`"commissionBasisPoints":300`) : réponse 201, statut `active`.
2. Il invite `gerant@jazz-club.test` (`"role":"admin"`) : 204, et Keycloak envoie « Update Your Account » à Mailpit (http://localhost:8025). Le lien mène à une page « Click here to proceed », puis au formulaire de mot de passe. Le gérant définit le sien, se connecte au front (espace organisation) et obtient un jeton de l'organisation `jazz-club`.
3. Le gérant crée un événement avec deux tarifs, le modifie, le publie : il apparaît dans `GET /api/events`.
4. Il invite un contrôleur (`POST /api/backoffice/invitations`, `"role":"controller"`) ; une fois son mot de passe défini, celui-ci scanne comme dans le parcours L2.
5. Un acheteur commande et paie deux billets : `GET …/sales` donne les billets par tarif, les places restantes, le total, la commission et le net dû ; l'événement est verrouillé, `PUT` et `DELETE …/publication` répondent 409.
6. `docker compose stop keycloak`, puis la création d'une organisation répond quand même 201 avec le statut `pending_keycloak` (une relance répond 502 tant que Keycloak est arrêté, et une invitation 409 `organization_not_active`). Keycloak redémarré (attendre `healthy`), `POST …/provisioning` répond 200 `active`.

Un utilisateur invité n'a pas de prénom ni de nom : le profil utilisateur du realm `stubandgo` ne les exige pas, sinon Keycloak refuserait la connexion (« Account is not fully set up ») tant qu'ils ne sont pas saisis.

## Suivre une commande dans les logs

Les logs sont du JSON sur stderr (Monolog), et chaque ligne porte `extra.trace_id`. Chaque réponse HTTP renvoie un en-tête `traceparent` (format W3C) ; un `traceparent` entrant est repris. L'identifiant de trace traverse l'outbox, le relais et RabbitMQ : le worker qui envoie l'email logue le même `trace_id` que la requête de paiement.

```bash
curl -si -X POST $A/api/orders/$O2/payment -H 'Content-Type: application/json' -d '{"cardNumber":"4242424242424242"}' | grep -i '^traceparent'
# traceparent: 00-<trace_id>-<span_id>-01
docker compose logs php worker | grep <trace_id>
```

On y retrouve, dans l'ordre, la requête (`php`), la commande `PayOrder`, l'événement `OrderPaid`, puis l'envoi des billets (`worker`). Le numéro de carte n'apparaît jamais dans les logs.

## Envois de billets en échec

Un envoi de billets qui échoue (serveur SMTP injoignable, par exemple) est retenté 10 fois par le worker, avec un délai croissant : environ 1 s, 3 s, 9 s… jusqu'à une heure entre deux essais. Après le dernier essai, le message n'est pas perdu : il est rangé dans le transport d'échec `failed` (file RabbitMQ `failed`, DSN `MESSENGER_FAILURE_TRANSPORT_DSN`). La métrique `stubandgo_ticket_bundles_undelivered` compte les commandes payées dont les billets n'ont pas encore été envoyés ; si elle ne redescend pas, regarder le transport d'échec :

```bash
docker compose exec php bin/console messenger:failed:show            # nombre de messages en échec
docker compose exec php bin/console messenger:failed:retry           # détail de chaque message, puis le relancer ou le supprimer
docker compose exec php bin/console messenger:failed:retry --force   # tout relancer sans confirmation
```

Avec RabbitMQ, `messenger:failed:show` donne seulement le nombre de messages (le transport AMQP ne sait pas les lister) ; `messenger:failed:retry` affiche le détail de chacun avant de le relancer. La file `failed` est aussi visible dans l'interface RabbitMQ. Un envoi relancé reste idempotent : des billets déjà envoyés ne repartent pas.

## Architecture

- Trois contextes, chacun un hexagone (`src/<Contexte>/Core`, `Infrastructure`, `UI`) : `Catalog` (organisations, événements, tarifs), `Sales` (commandes, places, paiement simulé), `Access` (billets, QR codes, scan). `src/Shared` est le noyau partagé. Les dépendances sont vérifiées par Deptrac.
- Quatre bus Messenger : `command.bus` (une transaction DBAL par commande), `query.bus`, `event.bus` (événements de domaine synchrones) et `async.bus`.
- Les messages asynchrones passent par une outbox transactionnelle : voir [docs/outbox.md](docs/outbox.md).
- Règles de projet détaillées (conventions, feature workflow) : `AGENTS.md`.

## Keycloak

Le service `keycloak` (`quay.io/keycloak/keycloak:26.8.1`, mode `start-dev`) démarre avec `make up`. Sa base (H2 de `start-dev`) vit dans le volume `keycloak_data` : redémarrer ou recréer le conteneur la conserve (les organisations créées via l'API restent), et `--import-realm` ignore un realm déjà présent. Les modifications des fichiers de `docker/keycloak/` ne sont donc prises en compte qu'après `make keycloak-reset` (supprime le conteneur et le volume) puis `make up`, qui réimporte l'état de démo. Le service `php` n'en dépend pas, la suite de tests ne le contacte jamais.

**Attention, `make keycloak-reset` ne touche pas la base de l'API.** Une organisation créée par l'API (espace plateforme) y reste `active` avec un `keycloak_id` qui ne désigne plus rien : ses invitations échouent (502) et ses membres n'existent plus. Pour revenir à une démo propre, réinitialiser Keycloak **et** recharger la base de démo : `make keycloak-reset`, `make up`, puis recréer la base comme dans [Démarrage](#démarrage) (`doctrine:database:drop --force`, `doctrine:database:create`, `doctrine:migrations:migrate -n`, `app:demo:load`, worker arrêté pendant ce temps).

Chaque realm exporté porte sa clé de signature RS256 (composant `rsa-demo-signing`, fournisseur `rsa`, clé privée et certificat dans le JSON). Sans elle, Keycloak tirerait une nouvelle paire de clés à chaque création du conteneur, alors que l'API garde en cache (`cache.app`, jusqu'à une heure) les clés publiques qu'elle a lues : tous les jetons seraient refusés (401) jusqu'à l'expiration du cache. Avec la clé figée, le `kid` annoncé par `/realms/<realm>/protocol/openid-connect/certs` ne change pas d'un conteneur à l'autre. **Ces clés sont du matériel de démonstration, publié dans le dépôt comme les mots de passe : elles ne doivent jamais servir ailleurs.** Les autres clés (chiffrement `RSA-OAEP`, HMAC, AES) restent générées à chaque démarrage.

| Realm | Émetteur | Contenu |
|---|---|---|
| `stubandgo` | `http://localhost:8180/realms/stubandgo` | organisations `demo-rock-asso` et `demo-theatre-ville`, rôles `organization-admin` et `organization-controller`, client public `stubandgo-app` (code + PKCE, redirection `http://localhost:5173/*` et `http://127.0.0.1:5173/*`), client confidentiel `stubandgo-admin-api` (compte de service de l'API, `client_credentials`, rôles `realm-management` `manage-organizations`, `manage-users`, `view-realm` ; secret de démo `stubandgo-admin-demo-secret`, jamais hors de la pile de dev) ; SMTP du realm vers Mailpit (`mailer:1025`, expéditeur `no-reply@stubandgo.test`) |
| `stubandgo-staff` | `http://localhost:8180/realms/stubandgo-staff` | rôle `platform-admin`, client public `stubandgo-staff-cli` (direct access grants autorisés) |

Comptes de démo, tous avec le mot de passe `stubandgo-demo` : `controleur.rock` (contrôleur, Rock Asso), `admin.rock` (administrateur, Rock Asso), `controleur.theatre` (contrôleur, Théâtre de la Ville) dans `stubandgo` ; `admin.plateforme` (`platform-admin`) dans `stubandgo-staff`. La console d'administration est `http://localhost:8180` (`admin` / `admin`).

Les navigateurs parlent à Keycloak par `http://localhost:8180`, l'API par `http://keycloak:8080` (variables `KEYCLOAK_PUBLIC_URL` et `KEYCLOAK_INTERNAL_URL` de `.env`). Keycloak annonce toujours l'émetteur public. `make keycloak-check` le vérifie depuis le conteneur `php` : il lit la discovery interne des deux realms et échoue si l'`issuer` n'est pas l'émetteur public (ou si Keycloak ne répond pas).

Le back-office pilote Keycloak par son API d'administration avec le client confidentiel `stubandgo-admin-api` du realm `stubandgo` : compte de service (`client_credentials`) aux seuls rôles `manage-organizations`, `manage-users` et `view-realm`. Son secret de démo `stubandgo-admin-demo-secret` est dans l'export du realm et dans `.env` (`KEYCLOAK_ADMIN_CLIENT_ID`, `KEYCLOAK_ADMIN_CLIENT_SECRET`) : valeur de démonstration, à ne jamais réutiliser hors de la pile de dev. Le jeton du compte de service n'est gardé qu'en mémoire, le temps d'une requête PHP-FPM : chaque requête qui appelle Keycloak en redemande un. Le realm envoie ses emails (invitations : mot de passe à définir, lien valable 3 jours) au SMTP de Mailpit, `mailer:1025`, expéditeur `no-reply@stubandgo.test` ; ils arrivent dans la même boîte Mailpit que les billets.

**Keycloak indisponible.** Une organisation créée alors reste `pending_keycloak` (201) et se provisionne ensuite par la relance ; une relance échoue en 502 `/problems/identity_provider_unavailable` ; une invitation échoue en 502 (l'utilisateur créé à moitié est supprimé) ou en 409 si l'organisation est en attente. Les jetons déjà émis restent valables (les clés publiques sont en cache).

## Commandes `make`

| Cible | Rôle |
|---|---|
| `make up` / `make down` | démarrer / arrêter la stack de développement |
| `make console CMD="debug:router"` | lancer une commande Symfony |
| `make test-db` | créer la base de test et la migrer |
| `make test` | suite PHPUnit |
| `make crap` | couverture et CRAP (échec si une méthode atteint 9) |
| `make mutation` | mutation testing (Infection, 100 % MSI exigé) |
| `make phpstan` | analyse statique (niveau max) |
| `make cs-check` / `make cs-fix` | style de code |
| `make deptrac` | règles de dépendances entre couches |
| `make lint-container` | lint du conteneur de production (alias d'autowiring que l'environnement de test redéfinit) |
| `make openapi` / `make openapi-check` / `make openapi-lint` | régénérer, vérifier, linter `openapi.yaml` |
| `make keycloak-reset` | supprimer Keycloak et son volume ; le prochain `make up` réimporte les realms de démo |
| `make keycloak-check` | vérifier que Keycloak annonce l'émetteur public des deux realms (stack démarrée, hors `ci`) |
| `make ci` | tous les contrôles |

`make help` liste toutes les cibles.

## Limites

- Le scan et le back-office exigent un jeton : le catalogue, la commande, le paiement et l'état de commande restent publics, et `/health` et `/metrics` restent ouverts à tout client qui atteint l'API (limite assumée de la L2).
- Un jeton du realm de la plateforme (`stubandgo-staff`, par exemple obtenu avec `stubandgo-staff-cli`) est accepté par l'API ; seul le rôle `platform-admin` ouvre les routes de la plateforme.
- Le back-office ne gère pas les membres au-delà de l'invitation : pas de liste, de changement de rôle ni de retrait ; un membre invité n'a ni prénom ni nom ; une invitation se refait depuis zéro (l'email doit être inconnu de Keycloak).
- Un événement ayant déjà vendu un billet ne peut plus être modifié ni dépublié ; un événement publié ne se modifie qu'après dépublication.
- Le compte de service et son secret sont des valeurs de démo, et le volume Keycloak n'est pas sauvegardé.
- Le paiement est simulé et aucun prestataire réel n'est appelé.

## Non-objectifs et évolutions possibles

Exclus de L1 à L3, ils pourraient venir ensuite :

- billets valables plusieurs jours ou pour plusieurs événements ;
- plan de salle et placement numéroté ;
- remboursement, annulation de commande, revente ;
- modification ou annulation d'un événement ayant déjà vendu un billet ;
- abonnement des organisations, modèle hybride, frais de service payés par l'acheteur ;
- quotas par tarif, justificatif de tarif réduit vérifié ;
- vrai prestataire de paiement et reversement réel aux organisations ;
- compte acheteur et historique des billets ;
- appartenance d'une même personne à plusieurs organisations ;
- mode hors connexion pour le scan ;
- très forts pics de trafic (file d'attente virtuelle) ;
- recherche et filtres dans les listes d'événements.
