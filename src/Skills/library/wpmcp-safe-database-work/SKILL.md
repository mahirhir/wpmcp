---
name: Safe database work
description: Inspect schema before you query, keep reads bounded, and reach for a WordPress API instead of SQL for anything that writes, because a raw row edit skips every hook the site depends on.
version: 1.0.0
tier: free
tags: [database, sql, safety, data]
requires:
  - wpmcp/list-tables
  - wpmcp/describe-table
  - wpmcp/query
---

# Touching the database directly

The database tools exist for the cases the WordPress APIs genuinely cannot
answer: a reporting question across tables, a plugin's custom table, a
diagnosis of orphaned rows. They are not a shortcut around the content tools.

The rule: **if a WordPress-level tool can do it, use that tool.** A raw UPDATE
on `wp_posts` skips the save hooks, so caches stay stale, search indexes drift,
translations unlink, and WooCommerce lookup tables go out of sync with the
posts they describe. The site looks fine and is quietly wrong.

## Always inspect before you query

1. `wpmcp/list-tables` to see what actually exists, including the site's table
   prefix. Never assume `wp_`; a hardened install often uses something else,
   and a query against a guessed prefix either errors or hits the wrong
   multisite table.
2. `wpmcp/describe-table` for the columns and types you are about to reference.
   Guessing a column name and getting an error is a wasted round trip; guessing
   a column name that exists with different semantics is a bug.

## Keep reads bounded

- Always include a LIMIT. A count first (`SELECT COUNT(*)`) then a bounded page
  is better than discovering the table has four million rows by hanging the
  request.
- Select the columns you need, not `*`. Every extra column is tokens you pay
  for and post content you did not need.
- Do not read user credential columns. `user_pass`, session tokens, and
  password-reset keys are never legitimate context for a task; the user tools
  exist and already exclude them.

## Writes

`wpmcp/insert-row`, `wpmcp/update-rows`, and `wpmcp/delete-rows` are
snapshotted, but a snapshot restores rows, not consequences. Before using them,
answer two questions out loud:

- Is there a WordPress-level tool for this? (`wpmcp/update-post`,
  `wpmcp/set-post-meta`, `wpmcp/update-product`, `wpmcp/update-option`,
  `wpmcp/update-user` ...) If yes, use it.
- What hooks am I skipping? If you cannot name them, you do not know enough to
  bypass them.

When a raw write really is correct, for example cleaning orphaned rows in a
plugin's own table:

1. Run the equivalent SELECT first and show the user the exact rows.
2. Get confirmation on that specific row set.
3. Write with a WHERE clause tight enough that the affected count matches the
   count you just showed. Never issue a write with no WHERE clause.
4. Read back and confirm the count.

## Schema changes

Do not. Adding, dropping, or altering tables and columns is outside what a
snapshot can restore, and it is not something to do on someone's site during a
chat. Recommend it, describe the migration, and let a human run it.

## Before anything large

`wpmcp/trigger-backup` and check `wpmcp/get-backup-status`. A backup taken
thirty seconds before a bulk operation is worth more than any amount of care
taken during it.
