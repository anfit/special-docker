# DynamoDB design

All persistence is one table (`SPECIAL_DYNAMODB_TABLE`, default `special`) with two global secondary indexes. The table is defined in [`deploy/aws/special-stack.yaml`](../deploy/aws/special-stack.yaml) for AWS and in [`src/Storage/DynamoSchema.php`](../src/Storage/DynamoSchema.php) for DynamoDB Local; the two must stay in sync (a test-free but cheap check: both list the same projected attributes).

The application talks to it only through the `Special\Storage\Store` interface; [`DynamoStore`](../src/Storage/DynamoStore.php) is the AWS implementation.

## Table

| | |
|---|---|
| Billing | on-demand (`PAY_PER_REQUEST`) |
| Keys | `pk` (S, hash), `sk` (S, range) |
| TTL attribute | `ttl` (epoch seconds) |
| Protection (AWS stack) | point-in-time recovery on, deletion protection on, `DeletionPolicy: Retain` |
| GSI `gsi1` | `gsi1pk` / `gsi1sk`, projection INCLUDE |
| GSI `gsi2` | `gsi2pk` / `gsi2sk`, projection INCLUDE |

Both indexes project the same non-key attributes: everything needed for list views, user listings and credential revocation, and **not** `body` or `password_hash`. Listing 50 specs therefore reads index entries of a few hundred bytes each, not 50 documents of up to 350 KB.

## Items

| Entity | `pk` | `sk` | GSI keys | TTL |
|---|---|---|---|---|
| user | `USER#<id>` | `META` | gsi1: `USERS` / `<lower username>#<id:12>` | |
| username lock | `USERNAME#<lower>` | `META` | | |
| spec (public, live) | `SPEC#<id>` | `META` | gsi1: `SPECS#PUBLIC` / `<updated_at>#<id:12>` | |
| spec (private, live) | `SPEC#<id>` | `META` | gsi2: `SPECS#PRIVATE#<owner>` / same sort key | |
| spec (deleted) | `SPEC#<id>` | `META` | none (removed from both indexes) | |
| counter | `COUNTER#spec` / `user` / `enabled_admins` | `META` | | |
| bootstrap marker | `BOOTSTRAP#admin` | `META` | | |
| audit entry | `AUDIT#<entity_type>#<entity_id or 0>` | `<created_at>#<rand>` | | |
| login attempts | `LOGIN#<sha256>` | `META` | | window + block |
| remember token | `REMEMBER#<selector>` | `META` | gsi1: `CRED#<user>` / `RM#<selector>` | expiry + 1 day |
| OAuth client | `OCLIENT#<client_id>` | `META` | | |
| authorization code | `OCODE#<hash>` | `META` | gsi1: `CRED#<user>` / `CODE#<hash>` | expiry + 1 day |
| access token | `OAT#<hash>` | `META` | gsi1: `CRED#<user>` / `AT#<hash>`; gsi2: `FAMILY#<f>` / `AT#<hash>` | expiry + 1 day |
| refresh token | `ORT#<hash>` | `META` | gsi1: `CRED#<user>` / `RT#<hash>`; gsi2: `FAMILY#<f>` / `RT#<hash>` | expiry + 1 day |

Timestamps are fixed-width UTC strings (`2026-01-02T03:04:05.123456Z`), so lexicographic order is chronological. `id:12` is the id zero-padded to 12 digits.

## Access patterns

| Need | How |
|---|---|
| Spec by id | `GetItem SPEC#id` (strongly consistent) |
| Visible specs, newest first | merge of two `Query`s: gsi1 `SPECS#PUBLIC` and gsi2 `SPECS#PRIVATE#<me>`, both descending; the service k-way merges them and applies filters |
| Projects visible to me | the same two streams, aggregated by lower-cased `project_name` |
| User by id / username | `GetItem USER#id` / `GetItem USERNAME#lower` then `GetItem` |
| All users | `Query gsi1 USERS` |
| Revoke everything of a user | `Query gsi1 CRED#<user>`, then revoke/delete each item |
| Revoke a refresh family | `Query gsi2 FAMILY#<f>` |
| Audit trail of an entity | `Query AUDIT#<type>#<id>` descending |

## Integer ids

URLs, share tokens, MCP schemas and cursors all use integer ids, so they are kept. `COUNTER#spec` and `COUNTER#user` are incremented with an atomic `UpdateItem ... ADD n :one ... ReturnValues UPDATED_NEW`. A failed create after the increment leaves a gap in the numbering; ids are unique and increasing, not dense.

## Concurrency and transactions

DynamoDB has no row locks, so every guarantee the PostgreSQL version got from `FOR UPDATE` is expressed as a **conditional write**:

| Guarantee | Mechanism |
|---|---|
| Spec edit does not overwrite a newer edit | `Put` with `revision = :expected AND attribute_not_exists(deleted_at)` in a transaction with the audit entry; the service re-reads and retries (up to 4 times), or reports `CONFLICT` when the caller supplied `expected_revision` |
| Mutation and audit entry commit together | `TransactWriteItems` (spec + audit, user + counter + audit, refresh rotation + two new tokens + audit, ...) |
| Unique usernames | transactional `Put` of `USERNAME#<lower>` with `attribute_not_exists(pk)` |
| Final enabled admin survives | `enabled_admins` counter adjusted in the same transaction with condition `n >= 2` before a decrement |
| First admin bootstrap runs once | `BOOTSTRAP#admin` marker with `attribute_not_exists(pk)` |
| Authorization code is single use | `UpdateItem SET consumed_at` with `attribute_not_exists(consumed_at)` |
| Refresh token replay detection | rotation updates the old token with `attribute_not_exists(revoked_at)`; if that fails the whole family is revoked |
| Remember-token rotation | transactional `Delete` of the old selector conditioned on its hash, plus `Put` of the new one |
| Login throttling is not racy | versioned conditional put; the loser re-reads and retries |

Reads that feed decisions (`GetItem`) are strongly consistent. **GSI queries are eventually consistent** (typically milliseconds): a freshly created spec can take a moment to appear in the list. The web UI redirects to `/specs/{id}` after creating, which reads the base table, so users do not notice.

## Limits worth knowing

- **Item size 400 KB.** `body` is capped at 350,000 bytes by the service, which leaves room for the other attributes. Large specs cost write units (1 WCU per KB, so a 100 KB edit is about 100 WCU, still fractions of a cent).
- **Transactions** cost twice the read/write units of plain operations. Volume here is tiny.
- **Hot partitions.** `SPECS#PUBLIC` and `USERS` are single index partitions. DynamoDB handles roughly 1,000 writes/s per partition; this application will see a handful per minute. Revisit only if you shard by project or tenant.
- **TTL deletion lags** by up to 48 hours. Every read that matters checks `expires_at` itself; TTL is only garbage collection.
- **Scan bound.** Filtering by status, project or text happens in PHP over the sorted streams (at most 1,000 rows per request). For a corpus of thousands of specs this is fine and cheap; if it grows into the tens of thousands, add a GSI per filter (for example `PROJECT#<lower name>`) and query it directly.

## Operating the table

- **Backups.** Point-in-time recovery (35 days) is enabled by the stack. Restoring creates a new table; point `SPECIAL_DYNAMODB_TABLE` at it (see the AWS guide).
- **Inspecting data.** `aws dynamodb get-item --table-name special --key '{"pk":{"S":"SPEC#1"},"sk":{"S":"META"}}'`, or the DynamoDB console's "Explore items".
- **Schema changes.** Adding attributes needs nothing. A new access pattern means a new GSI (added online with `update-table` or in the template) and, for existing rows, a small backfill script. Changing existing key formats is a data migration: write it as a script under `scripts/`, run it once, and record it here.
- **Local development.** `docker compose up` starts DynamoDB Local with a volume; `scripts/init-table.php` creates the table from `DynamoSchema`.
