# zFolio Feed API, v1

The contract between zFolio and the zFolio for WordPress plugin (slug `bristlecone-zfolio`). A zFolio Pro owner picks a document or a folder and chooses *Connect to WordPress*; zFolio publishes it as JSON at a private, unguessable address, and the plugin reads it. Content changes only when the owner chooses Update in zFolio, so drafts never reach WordPress.

zFolio keeps this folder up to date by pull request, and announces each change in an issue labeled `zfolio-api`.

| File | What it is |
| --- | --- |
| `README.md` | This contract. |
| [`schemas/`](schemas/) | JSON Schemas (2020-12) for the manifest, a page, an error, and a webhook event. |
| [`fixtures/`](fixtures/) | Sample answers and events, valid against the schemas. |
| [`kses-allowlist.json`](kses-allowlist.json) | The HTML a page may hold, for `wp_kses`, at `allowlistVersion` 1. Made from zFolio's own sanitizer, so the two never differ. |
| [`test-vectors/signatures.json`](test-vectors/signatures.json) | Webhook signature test vectors. |

## Disclosure

The plugin's readme may name these:

- The feed host, `zfolio.zuuid.cc`, and the image host, `zfolio-images.zuuid.cc`, both run by zFolio.
- zFolio's [Terms of Use](https://bristleconeit.com/policies/terms/zsuite/zfolio/) and [Privacy Policy](https://bristleconeit.com/policies/privacy/zsuite/zfolio/).
- What the plugin sends: requests for the feed and its pages, with its `User-Agent`, and answers to webhooks. Nothing about the site's visitors.
- Images: unless the site copies them into its media library, pages show them from `zfolio-images.zuuid.cc`, so each visitor's browser loads them from zFolio's image host, which receives the visitor's IP address and browser details as any web host does.

## Addresses

- A feed's address is `https://zfolio.zuuid.cc/feed/v1/<token>`. The token is `zff_` followed by 43 base64url characters (256 random bits). It is the credential: anyone with it can read the feed. The owner can rotate it or turn the feed off at any time.
- A page is at `https://zfolio.zuuid.cc/feed/v1/<token>/pages/<id>`, or `/pages/<slug>`. The id never changes; prefer it.
- The major version is in the path. Additive changes (a new field, a new optional attribute) stay in v1 at any time, so ignore what you don't know.
- Images are on `https://zfolio-images.zuuid.cc`. Their URLs never change for the same image.
- Read only: `GET` and `HEAD`. No CORS headers: read the feed from the WordPress server, never a browser.
- Send `User-Agent: bristlecone-zfolio/<plugin version> WordPress/<version>`. zFolio counts plugin versions from it, never addresses.

## The Manifest

`GET /feed/v1/<token>` ([schema](schemas/manifest.schema.json), [sample](fixtures/manifest.json))

```json
{
  "apiVersion": 1,
  "feedId": "zfd_Q2hhbmdlTWVJbkZpeHR1MA",
  "kind": "folder",
  "title": "Field Guide",
  "revision": 12,
  "updatedAt": "2026-11-07T11:00:00.000Z",
  "allowlistVersion": 1,
  "folders": [
    { "id": "fl_abcdefghijklmnopqrstuvwxyz", "title": "Setup", "parents": [], "order": 0 }
  ],
  "pages": [
    {
      "id": "pg_abcdefghijklmnopqrstuvwxyz",
      "slug": "install",
      "title": "Install",
      "description": "The first paragraph, as plain text.",
      "parents": ["fl_abcdefghijklmnopqrstuvwxyz"],
      "order": 0,
      "updatedAt": "2026-11-07T10:00:00.000Z",
      "hash": "64 lowercase hex characters"
    }
  ],
  "nav": []
}
```

- `feedId`: the feed's public id, `zfd_` and 22 base64url characters. It stays the same when the address rotates, and every webhook carries it.
- `kind`: `document` (one page) or `folder`.
- `revision`: an integer that only grows. It rises with every change to what the feed serves: an Update that changes anything, and a page hidden, shown again, or deleted outside an Update. A webhook whose `revision` is at or below the one you have is stale.
- `allowlistVersion`: the HTML allowlist the pages keep to (below).
- `folders[]`: the folders below the feed's own folder that hold pages, each with an opaque id (`fl_` and 26 base32 characters), its name, and its own folders' ids, outermost first. The feed's own folder is not listed.
- `pages[]`, in the owner's order:
  - `id`: opaque, `pg_` and 26 base32 characters, unique within the feed, the same for the same document for the feed's whole life, whatever its title or folder. Never zFolio's own document id.
  - `slug`: one segment of lowercase `a-z0-9` runs joined by single hyphens, at most 200 characters, never `/`, unique within the feed (a clash gets `-2`, `-3`). It changes when the title does; the id doesn't.
  - `parents`: the page's folders' ids, outermost first; `[]` at the top.
  - `title` and `description` are plain text. `updatedAt` and every other time are RFC 3339 in UTC.
  - `hash`: changes whenever the page's content does. Refetch a page only when its hash changed.
- `nav[]`: a bar of links for the feed (`{ "title", "pageId" }` or `{ "title", "url" }`). Always empty in v1 today.
- Caps in v1: 2,000 pages, 50 MB of pages, and 4 MB of manifest. There is no pagination in v1, so a manifest is always complete: a page missing from it is gone.

## A Page

`GET /feed/v1/<token>/pages/<id or slug>` ([schema](schemas/page.schema.json), [sample](fixtures/page.json))

```json
{
  "apiVersion": 1,
  "feedId": "zfd_Q2hhbmdlTWVJbkZpeHR1MA",
  "id": "pg_abcdefghijklmnopqrstuvwxyz",
  "slug": "install",
  "title": "Install",
  "description": "The first paragraph, as plain text.",
  "parents": ["fl_abcdefghijklmnopqrstuvwxyz"],
  "html": "<h1 id=\"user-content-install\">Install</h1>…",
  "headings": [{ "level": 1, "text": "Install", "id": "user-content-install" }],
  "publishedAt": "2026-11-01T09:00:00.000Z",
  "updatedAt": "2026-11-07T10:00:00.000Z",
  "hash": "64 lowercase hex characters"
}
```

- The manifest is the source of truth for `slug` and `parents`; a `304` for a page can carry an older slug.
- `headings[]`: each heading with an id, in order, at most 1,000, its text at most 300 characters. Its `id` is the heading's `id` in `html`.
- `publishedAt`: when the page first entered the feed. `updatedAt`: when its document last changed before the Update that sent it.

## The HTML

zFolio renders and sanitizes every page itself; WordPress never sees Markdown. Still run `html` through `wp_kses` with the bundled allowlist for `allowlistVersion` 1 ([`kses-allowlist.json`](kses-allowlist.json)), and strip anything outside it. The allowlist is fixed per version and ships inside the plugin; zFolio announces any new element or attribute in a `zfolio-api` issue, and a new one only appears once the plugin supports it.

- Never: `script`, `style` elements or attributes, `iframe`, `svg`, forms, or event handlers.
- MathML presentation elements only: `math`, `semantics`, `annotation` (with `encoding="application/x-tex"` only), `mrow`, `mi`, `mn`, `mo`, `ms`, `mtext`, `mspace`, `msup`, `msub`, `msubsup`, `mfrac`, `msqrt`, `mroot`, `mover`, `munder`, `munderover`, `mtable`, `mtr`, `mtd`, `mstyle`, `mpadded`, `mphantom`, `menclose`, with presentation attributes only. A site that would rather not show MathML can strip it and print the TeX annotation.
- Ids and names start with `user-content-`.
- Every `img` has an `alt` (empty when the author wrote none). `width` and `height` are in the allowlist but not written yet.
- Images keep loading while a feed is paused. Once a feed is revoked, its images are deleted when nothing else of the owner's shows them, so a site that must keep showing a page's images after that should copy them into its media library.
- A page's optional `lang`, and `lang` on elements, are in the schema and the allowlist but not written yet.
- Classes zFolio writes: `wiki-link`, `wiki-link-missing`, `content-block`, `footnotes`, `data-footnote-backref`, `task-list-item`, `contains-task-list`, `sr-only`, `language-*` on code, and `hljs-*` on highlighted code.

### Links

- A link to another page of the same feed is `<a href="#" data-zfolio-slug="install">`, with `data-zfolio-heading="user-content-…"` when it points at a heading on that page. Rewrite it to that page's WordPress address. The feed's address never appears in a page.
- A link to anything outside the feed is its text, unless the author wrote an ordinary URL.
- Reserved, not written yet: `data-zfolio-url` (a public address for a page outside the feed) and `data-zfolio-embed` (an embed sent as a link, for WordPress oEmbed). Both are in the v1 allowlist so they can start without a new version.

## Answers and Errors

| Answer | When |
| --- | --- |
| `200` | The manifest or page. |
| `304` | `If-None-Match` names the current `ETag`. The manifest's ETag is its `revision`, a page's its `hash`, both quoted (`"12"`). A weak `W/` match counts. |
| `404` | JSON ([schema](schemas/error.schema.json)) `{ "apiVersion": 1, "code", "message" }`, `code` being `not_found` (an unknown address, a rotated address after its grace, a page the feed doesn't serve, or a feed never updated), `paused` (the owner's account is set to be deleted, or no longer has Pro), or `revoked` (the owner turned the feed off; said for 30 days, then `not_found`). |
| `410` | A retired major version: `{ "code": "gone", "currentVersion", "docs" }`. None yet. |
| `429` | Too many requests from the client's network (300 in 10 seconds on every zFolio public host), with `Retry-After` in seconds. Plain text. |

- Only `paused` and `revoked` (or a signed webhook) should take content down. A `not_found`, a timeout, a network error, or a `5xx` should never change what the site shows.
- Every answer carries `Cache-Control: public, max-age=0, s-maxage=60`, `X-Robots-Tag: noindex, nofollow, noarchive`, and `Referrer-Policy: no-referrer`. zFolio's edge caches each address for up to 60 seconds and purges it on an Update, so a `304` costs almost nothing.
- Polling: at most once every 5 minutes per connection (15 by default, with jitter), one request at a time, honoring `Retry-After`.

## Rotating the Address

The owner can rotate a feed's address *with grace* (the default), which keeps the old address working for 7 days, or *now*, for an address that leaked, which stops it at once. The feed's id, pages, and webhooks stay. After the grace, the old address answers `not_found`.

## Webhooks

A webhook tells the site its feed changed, so it fetches the manifest at once instead of on its next poll. The plugin should still poll: an event can be late or lost.

- A feed has up to 3 endpoints, each `https` on a public host (no IP address, port, user, or local name), each with its own secret (`zfwh_` and 43 base64url characters, shown once when the endpoint is added or its secret rolled). Each endpoint keeps the API version it registered with.
- Every event is `{ "id", "type", "createdAt", "feedId", "apiVersion", "data" }` with no content ([schema](schemas/event.schema.json), [samples](fixtures/events.json)). The same `id` goes to each of a feed's endpoints and stays the same on every retry. Types:
  - `feed.updated`: `data.revision`, `data.changed[]`, and `data.removed[]` (page ids, at most 100 each, with `data.truncated: true` when more were left out). Always fetch the manifest and compare hashes. Also sent when a trashed document comes back.
  - `feed.page_removed`: `data.revision` and `data.pages[]`, only when a page leaves outside an Update, because its document was trashed or deleted.
  - `feed.paused` and `feed.resumed`: `data.revision`, when the owner's Pro lapses or comes back. A pause for a deletion request sends no event; the feed answers `paused`.
  - `feed.revoked`: the owner turned the feed off, or its document or account was deleted.
  - `feed.rotated`: `data.what` is `address` (with `graceUntil` when the old address keeps working) or `secret` (with `previousUntil`, sent only to the endpoint whose secret rolled, signed with both).
  - `feed.test`: sent when the owner chooses Test.
- Headers: `Content-Type: application/json`, `User-Agent: zFolio-Webhooks/1 (+https://zfolio.cc)`, `zFolio-Event-Id`, `zFolio-Event-Type`, and `zFolio-Signature: t=<unix seconds>,v1=<signature>`. The signature is lowercase hex HMAC-SHA256, keyed with the secret string's UTF-8 bytes, over `t + "." + raw body`. Every attempt is signed with a fresh `t`. While a secret is rolled, events carry a second `v1=` signed with the previous secret, until the owner confirms the new one or 7 days pass. Reject a `t` more than 5 minutes off, compare in constant time, accept the event if any `v1=` matches, and drop an event `id` seen in the last 25 hours.
- Delivery: an event the owner's action causes is sent right after it; one from a document trashed or deleted, or from the daily plan check, is sent within the hour. The endpoint has 5 seconds. Redirects are not followed. A `2xx` is delivered. `401`, `404`, and `410` turn the endpoint off at once. Anything else (a timeout, `429`, `5xx`, a redirect) is retried with backoff (about 1, 2, 4, then every 6 hours) for 24 hours, then the endpoint turns off. The owner sees why in zFolio and can turn it on again. The response body is ignored; answer `202` quickly and fetch afterwards.
- Test vectors, including a rolled secret's two signatures: [`test-vectors/signatures.json`](test-vectors/signatures.json). The first: the secret `zfwh_0123456789abcdefghijklmnopqrstuvwxyzABCDEFG`, `t` `1794000000`, and body `{"id":"evt_test"}` sign to `d92c01018b7af0a59010b4fd4ca5638ef9f8a450f303a590e6b81d6181c8138d`.

## Versions and Support

Additive changes stay in v1. A breaking change becomes v2, served beside v1. From the day a plugin release that supports v2 is live on WordPress.org, v1 keeps answering, unchanged, for 12 months, with `Deprecation` (RFC 9745), `Sunset` (RFC 8594), and a `Link` to the migration notes on every answer and webhook. After the sunset, v1 answers `410`. Each release that changes the feed or webhooks has a `Feed API:` line in zFolio's CHANGELOG and a `zfolio-api` issue on the plugin repository.
