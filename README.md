# glueful/lemma-collections

Developer-defined **data collections** for [Lemma](https://getlemma.dev) — schemas backed by
**real per-collection tables**, with an auto-generated CRUD/query API, an admin
schema builder, per-operation access policies, soft relations, and emitted change events — packaged
as a **removable capability pack**. It depends only on the framework and `glueful/lemma-contracts`;
install it, disable it, or `composer remove` it without touching the core.

Each collection is a first-class table (`coll_<name>`), not a JSON blob, so rows are queryable,
indexable, and relationable like any other table — while the schema is defined and evolved entirely
through the admin API.

## What it provides

- **Schema management** (`CollectionManager`) — create a collection (validated name → `coll_<name>`
  table with the standard system columns: `id`, `uuid`, timestamps, `created_by_*`/`updated_by_*`),
  add/drop fields, add/remove indexes, replace the access policy, set field order, and drop the
  collection. In-place field-type changes are blocked; destructive ops require a typed confirmation
  (waived on an empty table).
- **Field types** — `collections.` `string` (VARCHAR), `text` (TEXT), `integer` (INT/BIGINT),
  `decimal`, `boolean`, `date`, `datetime`, `json`, `email`, `url`, `enum`, `relation`, `asset`.
  Each declares filterable/sortable/indexable capabilities.
- **Public data API** — `GET/POST/PATCH/DELETE /v1/collections/{name}` (list with filter/sort/
  field-projection/expand + offset pagination, get, create, bulk-create, update, delete). Behind an
  optional API key + a per-collection scope gate (`collections.{name}.{read|write|delete}`) driven
  by the collection's access policy.
- **Admin schema API** — `/v1/admin/collections` (index/show/store/add-field/drop-field/add-index/
  drop-index/update-access/destroy) behind `auth` + Aegis `lemma_permission`.
- **Access policy** — per operation `{read, write, delete}`, each `public` (no auth) or `scoped`
  (api-key scope OR the caller's session permission). Defaults to all-`scoped`.
- **Soft relations** — a `relation` field targets another collection (`collection:<name>`) or the
  framework users table (`users`); existence-validated, one-level batch expand, restrict-on-delete.
- **Change events** — pure `CollectionRow{Created,Updated,Deleted}` (data) and
  `Collection{Created,Updated,Dropped}` (schema) events for subscribers (audit, analytics, webhooks,
  search) to consume without coupling to the pack.

## The capability

The provider registers a single capability in `boot()`:

```php
new Capability('lemma.collections', label: 'Data collections', description: '…');
```

- **Enabled by default.** Disable it by setting `'lemma.collections' => false` in `config/lemma.php`'s
  `capabilities` switchboard.
- **Gated, not just UI.** When disabled, the public + admin routes are never registered (requests
  `404`, not a live-but-disabled handler). Migrations run on **install**, not enable, so disabling
  the capability preserves the `collection_definitions` metadata and every `coll_*` data table.
- **Permissions.** The pack declares `collections.manage`, `collections.schema.manage`, and
  `collections.data.manage`; the host app grants them to `administrator` in its own dependent
  migration.

## Boundary

Depends on `glueful/lemma-contracts` and `glueful/framework` — and **never** on `glueful/lemma` (the
application). The repo's `composer boundaries` check enforces this at both the Composer-dependency
and source level (no `App\` references in `src/`).

## Install

The pack is **bundled by default** in the Lemma create-project template. To add it to an existing app
(it lives as a path package in this monorepo):

1. `composer require glueful/lemma-collections`
2. `./lemma extensions:enable lemma-collections` (writes the provider into the
   `config/extensions.php` allow-list and recompiles the extension cache)
3. `./lemma migrate:run` to create the metadata tables.

## Remove

`./lemma extensions:disable lemma-collections`, then `composer remove glueful/lemma-collections`. The
CMS core boots unchanged. The `lemma.collections` capability disappears from
`GET /v1/admin/capabilities`, so the collections admin section hides automatically, and the public
`/v1/collections/*` surface is gone. Existing `coll_*` tables remain on disk (drop them manually if
you want the data gone).
