=== zFolio for WordPress ===
Contributors: bristleconeit
Tags: documentation, knowledge base, docs, feed, content sync
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Show documents and folders you write in zFolio on your WordPress site, styled by your theme.

== Description ==

zFolio for WordPress is the official zFolio plugin for WordPress. Write documentation and other content in zFolio, then show it on your site with a block or a shortcode. Your theme handles the look.

* **Connect a document or a folder.** In zFolio, choose Connect to WordPress and paste the feed address into Bristlecone → zFolio. A site can have several connections.
* **Only published content.** Pages change on your site only when you choose Update in zFolio, so drafts never appear.
* **Cached in WordPress.** Pages are stored in your site’s database, so visitors never wait on zFolio and an outage never empties your site.
* **Stays current.** The plugin checks each feed for changes every 15 minutes by default (5 to 60 minutes is available), fetches only the pages that changed, removes pages you removed, and clears page caches for the posts that show them.
* **Refresh by hand.** Refresh a whole connection or a single page, or clear stored content and fetch it again.
* **Links between pages work.** Links from one zFolio page to another point at the post that shows it on your site.
* **Math.** Formulas show as MathML, or as their TeX source if you prefer.
* **Respects the owner.** If a feed is paused or turned off in zFolio, the plugin hides its content (or keeps the last version and tells admins, if you choose that).

Use the **zFolio Page** block, or the shortcode shown next to each page in Bristlecone → zFolio:

`[zfolio connection="c1a2b3c4d5" page="pg_…"]`

Webhooks for instant updates and a mirror mode that keeps a whole folder as WordPress pages are coming in later versions.

= External Services =

This plugin connects to zFolio, a writing service run by Bristlecone IT Services, to read the documents and folders you choose to publish to your site.

* **What is sent and when:** when you add a connection, choose Test Connection or Refresh Now, and on each scheduled check (every 15 minutes by default), your site requests the feed and any changed pages from the feed host, `zfolio.zuuid.cc`. Each request carries the feed address you pasted and a User-Agent naming the plugin and WordPress versions. Nothing about your site’s visitors is sent.
* **Images:** pages show their images from zFolio’s image host, `zfolio-images.zuuid.cc`. Each visitor’s browser loads those images from that host, which receives the visitor’s IP address and browser details, as any web host does.
* zFolio [Terms of Use](https://bristleconeit.com/policies/terms/zsuite/zfolio/) and [Privacy Policy](https://bristleconeit.com/policies/privacy/zsuite/zfolio/).

== Installation ==

1. Install and activate the plugin.
2. In zFolio, open the document or folder you want on your site and choose Connect to WordPress.
3. In WordPress, go to Bristlecone → zFolio, choose Add Connection, and paste the feed address.
4. Add a zFolio Page block to a post or page and choose the page to show.

== Frequently Asked Questions ==

= Does my site need to be public? =

No. The plugin checks for changes on a schedule, so it works on any site that can reach zFolio. A low-traffic site may check a little later than scheduled, because WordPress runs scheduled tasks when someone visits.

= Is the feed address secret? =

Yes. Anyone with the address can read the feed, so the plugin hides it once saved. If it leaks, rotate it in zFolio and paste the new one.

= Does WordPress see my Markdown? =

No. zFolio sends finished HTML, and the plugin cleans it again with a fixed list of allowed elements before storing it.

== Changelog ==

= 0.1.0 =
* First development version: connections, scheduled checks, stored pages, the zFolio Page block and the shortcode.

== Upgrade Notice ==

= 0.1.0 =
First development version.
