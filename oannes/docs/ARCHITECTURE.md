# Oannes Architecture

## Goal

Oannes is a replacement for a customized Snac instance, optimized for small,
closed communities. It must be reliable, transparent, and recoverable by a
human administrator with normal shell access.

## Non-Goals

- No SQL database.
- No external queue server.
- No mandatory Node.js build step.
- No timeline state that cannot be rebuilt from canonical files.

## File Layout

```text
data/
  objects/aa/<sha256(activitypub-id)>.json
  actors/aa/<sha256(actor-id)>.json
  actors/local/<uid>.json
  keys/local/<uid>.json
  social/<uid>/followers/*.json
  social/<uid>/following/*.json
  indexes/*.json
  queue/pending/*.json
  queue/done/*.json
  xml/
  tmp/
  logs/
```

## Canonical Data

Only canonical JSON files under `objects/` and `actors/` are authoritative.
Everything under `indexes/` is derived.

Local keys and social graph snapshots are also plain JSON. They are operational
state, not a database: each file is independently inspectable, replaceable, and
safe to copy during migration.

## Thread Rule

A reply relationship exists only when the child object has an ActivityPub
`inReplyTo` that resolves to the parent object's `id` or one of the parent's
known aliases.

The following fields must never create reply parentage:

- `content`
- `sourceContent`
- `attachment.href`
- link preview URLs
- article URLs cited by a note
- mentions
- boosts/announces unless explicitly represented as their own relation

This rule prevents the observed Snac bug where replies to a linked article were
displayed as replies to the note that linked the article.

## Atomic Writes

Each write goes to `data/tmp`, is flushed, and then moved into place with
`rename()`. A partially written canonical object should never appear at its
final path.

## Recovery

If indexes become inconsistent:

```sh
rm oannes/data/indexes/*.json
php oannes/bin/oannes.php rebuild-index
php oannes/bin/oannes.php validate-threads
```

## Outbound Delivery

Outgoing ActivityPub delivery is file-queued under `data/queue/pending`. The
worker signs POST requests with the local actor key, includes `Date`, `Digest`,
`Host`, `Content-Type`, and an HTTP Signature over `(request-target) host date
digest content-type`.

Delivery is disabled by default with `delivery_enabled => false`. In that mode
`queue-run` prepares and validates jobs but does not send network requests.

## Inbound Inbox

The local inbox is disabled by default with `inbox_enabled => false`. When
enabled, it accepts signed ActivityPub JSON only from actors already known in
the local actor store, writes the accepted activity to `data/inbox/accepted`,
and enqueues an `inbox` job. It does not automatically approve follows or mutate
conversation indexes during receipt.

`inbox-run` processes those jobs into `data/moderation/inbox/<uid>/...` review
files. `Follow` and `Create` activities are separated from other activity types
so admin tooling can make closed-community moderation decisions before changing
the canonical object store or social graph.

Approving a `Follow` adds the remote actor to `data/social/<uid>/followers` and
queues a signed `Accept`. Rejecting queues a signed `Reject` without changing
the follower graph. Delivery still obeys `delivery_enabled`.

Approving a `Create` stores only publishable embedded objects (`Note`,
`Article`, `Page`, `Question`) in the canonical object store and rebuilds
derived indexes. Rejection marks the review file and leaves canonical objects
unchanged.

## Simulations

`php oannes/bin/oannes.php simulate 20` runs repeated isolated simulations in
temporary file stores. The suite exercises signed inbox receipt, duplicate
rejection, follow approval/rejection, create moderation, reply indexing,
delivery dry-run behavior, and the rule that content links never create
parentage.

`php oannes/bin/oannes.php readiness 20` combines simulations, queue state,
configuration gates, index counts, and thread validation into a cutover-oriented
report.
