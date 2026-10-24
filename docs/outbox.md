# Outbox transactionnelle

Ce document explique le pattern *transactional outbox* et la façon dont stubandgo l'implémente. Le code se trouve dans `src/Shared/Infrastructure/Outbox/`, le schéma dans `migrations/Version20261024120000CreateOutbox.php` et les deux migrations qui le complètent.

## 1. Le problème : la double écriture

Quand un paiement est confirmé, deux choses doivent se produire :

1. l'état de la commande change en base (`paid`), les billets sont créés ;
2. un message part dans RabbitMQ pour que le worker envoie l'email des billets.

Ce sont deux systèmes différents, sans transaction commune. Quel que soit l'ordre choisi, une panne entre les deux laisse un état incohérent :

| Ordre | Panne entre les deux étapes | Conséquence |
|---|---|---|
| Base, puis RabbitMQ | RabbitMQ indisponible après le commit | Commande payée, email jamais envoyé |
| RabbitMQ, puis base | Rollback de la transaction après l'envoi | Email envoyé pour un paiement annulé |
| RabbitMQ pendant la transaction | Commit qui échoue après l'envoi | Même cas que la ligne précédente |

## 2. Le pattern

Au lieu de publier le message, on l'**écrit dans une table de la même base**, dans **la même transaction** que le changement d'état. Soit tout est validé (la commande et son message), soit rien ne l'est.

Un processus séparé, le **relais**, lit ensuite les messages en attente, les publie dans RabbitMQ et les marque comme publiés.

```mermaid
sequenceDiagram
    participant H as Command handler
    participant DB as PostgreSQL
    participant R as Relais (worker)
    participant MQ as RabbitMQ
    participant C as Consommateur

    H->>DB: BEGIN
    H->>DB: UPDATE sales_order SET status = 'paid'
    H->>DB: INSERT INTO outbox_message (...)
    H->>DB: COMMIT
    Note over H,DB: état et message validés ensemble

    loop toutes les 5 secondes
        R->>DB: BEGIN ; SELECT … FOR UPDATE SKIP LOCKED
        R->>MQ: publish(message)
        R->>DB: UPDATE outbox_message SET published_at ; COMMIT
    end

    MQ->>C: message
    C->>C: traitement idempotent
```

La garantie obtenue est **« au moins une fois »** : un message validé finit toujours par être publié, mais il peut l'être deux fois (voir § 5). Les consommateurs doivent donc être idempotents.

## 3. L'implémentation dans stubandgo

### 3.1 Les pièces

| Élément | Rôle |
|---|---|
| `AsyncMessageInterface` | Interface marqueur de tout message asynchrone. Messenger route cette interface vers le transport `async` (RabbitMQ), voir `config/packages/messenger.yaml`. |
| `OutboxWriterInterface` / `DbalOutboxWriter` | Écrit un message dans `outbox_message`, sur la connexion DBAL partagée, donc dans la transaction en cours. N'ouvre ni ne valide aucune transaction lui-même. |
| `OutboxRelay` | Lit un lot de messages en attente, les reconstruit, les publie sur `async.bus` et les marque publiés. |
| `OutboxSchedule` | Planning Symfony Scheduler (`#[AsSchedule('default')]`) : un message `RelayOutbox` toutes les 5 secondes. |
| `RelayOutbox` / `RelayOutboxHandler` | Le message planifié et son handler, qui appelle `OutboxRelay::relayBatch()`. |
| `InvalidOutboxMessageException` | Levée quand la classe d'une ligne n'existe plus ou n'est pas un message asynchrone. |

### 3.2 La table `outbox_message`

| Colonne | Type | Rôle |
|---|---|---|
| `id` | `uuid` | UUID v7, ordonné dans le temps ; départage deux lignes de même `created_at`. |
| `message_class` | `text` | Nom complet de la classe du message, pour le reconstruire. |
| `payload` | `jsonb` | Propriétés du message normalisées (scalaires et tableaux uniquement). |
| `created_at` | `timestamptz` | Date d'écriture ; les messages partent du plus ancien au plus récent. |
| `published_at` | `timestamptz` null | Date de publication. `NULL` tant que le message attend, et pour un message en quarantaine. |
| `failed_at` | `timestamptz` null | Date de mise en quarantaine. `NULL` pour un message sain. |
| `failure_reason` | `text` null | Raison de la quarantaine (classe et erreur de décodage, jamais le payload). |

Un message **en attente** a `published_at IS NULL AND failed_at IS NULL`. Un index partiel sur `(created_at, id)` couvre exactement ce prédicat : la requête du relais parcourt l'index dans l'ordre et s'arrête à la limite du lot, quelle que soit la taille de l'historique. Chaque table et colonne porte un commentaire PostgreSQL (`\d+ outbox_message` dans `psql`).

### 3.3 Côté écriture

Le code métier ne connaît pas l'outbox. Un contexte déclare un port dans son domaine (par exemple, en Tâche 12, `TicketDeliveryRequesterInterface` dans `Access`). Son adaptateur d'infrastructure construit le message et appelle `OutboxWriterInterface::store()`.

L'atomicité vient du bus de commandes : `DbalTransactionMiddleware` ouvre une transaction DBAL autour de chaque commande, et les listeners d'événements synchrones s'exécutent à l'intérieur. Tout ce qui est écrit pendant la commande, outbox comprise, est validé ou annulé d'un bloc. Un test le vérifie : un message écrit dans une commande qui échoue n'est jamais relayé (`testMessageStoredInRolledBackCommandIsDiscarded`).

Le message est normalisé par le Serializer de Symfony et stocké en JSON. C'est un **DTO de transport** : il ne contient que des types simples, et le handler qui le consomme reconstruit les value objects dès son entrée. Le domaine ne voit jamais une primitive.

### 3.4 Côté relais

`OutboxRelay::relayBatch(50)` fait tout dans **une seule transaction** :

1. `SELECT … WHERE published_at IS NULL AND failed_at IS NULL ORDER BY created_at, id LIMIT 50 FOR UPDATE SKIP LOCKED` ;
2. reconstruit **toutes** les lignes du lot avant le moindre envoi ;
3. met en quarantaine celles qui ne se reconstruisent pas (§ 3.5) ;
4. publie les autres sur `async.bus`, qui les route vers RabbitMQ ;
5. renseigne `published_at` pour les messages publiés.

`FOR UPDATE` verrouille les lignes jusqu'au commit ; `SKIP LOCKED` fait qu'un second worker lancé en parallèle prend les lignes suivantes au lieu d'attendre ou de republier les mêmes. Les verrous durent donc pendant l'envoi vers RabbitMQ, ce qui est le prix de cette garantie.

Le relais tourne dans le worker : `bin/console messenger:consume async scheduler_default`. Le transport `scheduler_default` fournit le message `RelayOutbox` toutes les 5 secondes. Ce message n'implémente pas `AsyncMessageInterface` : il est traité sur place et ne repasse jamais par RabbitMQ.

### 3.5 Quarantaine des messages illisibles

Une ligne peut devenir impossible à reconstruire : classe renommée ou supprimée, payload qui ne correspond plus au constructeur, garde du constructeur qui refuse les données.

Si le relais levait simplement une exception, deux défauts apparaîtraient :

- **blocage de la file** : la ligne fautive reste la plus ancienne et fait échouer chaque passage, donc plus aucun message ne part ;
- **renvois en boucle** : les messages valides placés avant elle dans le lot auraient déjà été publiés, puis leur marquage annulé par le rollback, et ils repartiraient toutes les 5 secondes.

Le relais intercepte donc toute erreur de décodage (`\Throwable`) et **met la ligne en quarantaine** : `failed_at` et `failure_reason` sont renseignés dans la même transaction, un log `error` « Outbox message quarantined. » est émis avec `outbox_message_id`, `message_class` et `reason`, sans le payload. La ligne n'est plus jamais sélectionnée. Comme toutes les lignes sont reconstruites avant le premier envoi, une ligne fautive ne provoque aucun renvoi des autres.

Les gardes de constructeur des messages ne doivent jamais recopier leur entrée dans le message d'exception : ce texte finit dans les logs et dans `failure_reason`.

Tests associés (`tests/Shared/Infrastructure/Outbox/`) : `testRelayRespectsBatchLimit`, `testRelayOrdersByCreationDateFirst`, `testRelayMarksMessageAsPublishedAndDoesNotResendIt`, `testItSchedulesOneRelayEveryFiveSeconds`, et pour la quarantaine `testPoisonRowIsQuarantinedAndGoodRowsAreNotResent`, `testPoisonRowBeforeAValidRowDoesNotBlockIt`, `testMessageWhoseConstructorThrowsIsQuarantinedAndDoesNotBlockTheQueue`, `testQuarantineIsLoggedAsErrorWithoutPayload`.

## 4. Ajouter un nouveau message asynchrone

1. Créer la classe du message dans `Infrastructure` du contexte, `final readonly`, avec des propriétés `string`, `int`, `bool`, `float` ou `array` uniquement, et lui faire implémenter `AsyncMessageInterface`.
2. Dans le domaine, déclarer le port qui exprime le besoin métier (« demander l'envoi des billets »), sans mentionner l'outbox.
3. L'implémenter dans `Infrastructure` en appelant `OutboxWriterInterface::store()`.
4. Écrire le handler du message (`#[AsMessageHandler(bus: 'async.bus')]`) : reconstruire les value objects, puis déléguer au command bus ou au cas d'usage. Le rendre idempotent.
5. Tester avec le transport `in-memory://` (configuré en environnement de test) : écrire le message dans une commande, appeler `relayBatch()`, puis vérifier `getSent()` sur le transport `async`.

## 5. Garanties et limites

- **Au moins une fois** : un message peut partir deux fois si l'envoi vers RabbitMQ réussit mais que le commit du relais échoue, ou si RabbitMQ tombe au milieu d'un lot (tout le lot est annulé, y compris les messages déjà envoyés). Les consommateurs doivent être idempotents ; le futur handler d'envoi des billets ne renverra pas un lot déjà livré.
- **Ordre** : les messages sont relayés du plus ancien au plus récent par un worker. Avec plusieurs workers, `SKIP LOCKED` répartit les lots et l'ordre global n'est plus garanti ; aucun consommateur actuel n'en dépend.
- **Latence** : jusqu'à 5 secondes entre le commit et la publication, plus la durée du lot.
- **Quarantaine** : rien ne rejoue automatiquement une ligne en quarantaine. Après correction de la cause, on la remet en attente à la main :

  ```sql
  SELECT id, message_class, failure_reason, failed_at
  FROM outbox_message
  WHERE failed_at IS NOT NULL
  ORDER BY failed_at;

  UPDATE outbox_message
  SET failed_at = NULL, failure_reason = NULL
  WHERE id = '<id>';
  ```

- **Non implémenté à ce stade** : la purge des lignes publiées (la table grossit indéfiniment), la mesure du retard et du nombre de messages en quarantaine (prévue avec `/health` et `/metrics`), et la propagation de l'identifiant de corrélation à travers l'outbox (prévue avec les logs structurés).
