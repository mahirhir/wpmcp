---
name: Editing Elementor pages surgically
description: Locate a single Elementor element by id and edit only that element, instead of rewriting a page's whole _elementor_data blob and losing everything you did not understand.
version: 1.0.0
tier: free
tags: [elementor, builder, editing]
requires:
  - wpmcp/get-elementor-data
  - wpmcp/find-element
  - wpmcp/update-element
---

# Editing Elementor without wrecking the page

An Elementor page is one serialized JSON tree stored in the `_elementor_data`
post meta. The single most destructive thing an agent can do on an Elementor
site is read that blob, regenerate "an equivalent" tree, and write it back. Any
setting you did not model is gone, and the page still renders, so nobody
notices until a client does.

Never hand-write a whole element tree. Edit nodes.

## Orient first

1. `wpmcp/detect-builder` on the post: confirm it is actually an Elementor
   page and not Gutenberg, Bricks, or Divi. Elementor tools on a non-Elementor
   page are a no-op at best.
2. `wpmcp/get-elementor-data` to read the current tree. Read it to understand
   the structure, not to regenerate it.
3. `wpmcp/find-element` to get the id of the specific widget or container you
   need. Every Elementor node has a stable id; that id is what makes a
   surgical edit possible.

## Then edit one node

- `wpmcp/update-element` changes settings on a single element by id. Send only
  the settings you are changing. Unspecified settings keep their current
  values.
- `wpmcp/add-widget`, `wpmcp/add-container`, `wpmcp/add-flexbox` insert new
  nodes at a stated position.
- `wpmcp/move-element`, `wpmcp/reorder-elements`, `wpmcp/duplicate-element`,
  `wpmcp/remove-element` restructure without touching settings.
- `wpmcp/set-element-label` names a node so the next person (or the next
  agent) can find it.

Check `wpmcp/get-widget-schema` before you invent a settings key. Elementor
widget settings are not free-form; a key you guessed is silently ignored, which
looks exactly like a tool that did not work.

## Global styling belongs in the Kit

A request like "make the buttons brand blue" is usually a Kit change, not
fifty element edits. `wpmcp/update-global-colors` and
`wpmcp/update-global-typography` change the site's design tokens; per-element
overrides then fight those tokens forever. Prefer the Kit, and say that you
are doing so.

## Verify and clear

After a structural change, read the element back. Elementor caches rendered
CSS per post, so if the change is correct in the data but invisible on the
front end, `wpmcp/clear-cache` before you conclude anything is broken.

## Reversibility

Every one of these writes is snapshotted; see the `wpmcp-safe-writes` skill.
If a structural edit went wrong, roll the operation back rather than trying to
reconstruct the previous tree by hand.

## If these tools are missing

The Elementor tools are pro tier and require Elementor to be active. If they
are not registered on this site, do not fall back to writing `_elementor_data`
through `wpmcp/set-post-meta` or `wpmcp/query`. That bypasses every safety and
schema check this skill exists to enforce. Report that Elementor editing is
unavailable instead.
