=== Visitrix – Lightweight Visitor Analytics ===
Contributors: yourwporgusername
Tags: analytics, statistics, visitors, traffic, stats
Requires at least: 6.2
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight self-hosted visitor statistics: traffic sources, search engines, devices, browsers, time on site and bounce rate. No external services.

== Description ==

Visitrix gives you a clean, informative traffic summary right in the WordPress admin – without third-party services, cookies or heavy scripts.

**What you get**

* Visits, visitors, pageviews, bounce rate, page depth, time on site and share of new visitors – with comparison to the previous period.
* Traffic chart by hour or by day.
* Traffic sources: direct, search engines, social networks, messengers, AI assistants, links from other sites, ads (UTM / yclid / gclid) and internal traffic.
* Search engines: Google, Yandex, Bing, DuckDuckGo, Mail.ru and others.
* New and returning visitors with bounce rate, depth and time on site for each group.
* Device type (PC, smartphone, tablet), browsers and operating systems.
* Popular pages, landing pages and UTM campaigns.
* Periods: today, yesterday, 3 days, week, month or any custom date range.
* Dashboard widget with today's numbers.

**How metrics are calculated**

* A *visit* is a sequence of pageviews with no more than 30 minutes of inactivity. Arriving from a different external source starts a new visit.
* A *bounce* is a visit with a single pageview that lasted less than 15 seconds.
* *Time on site* is measured with lightweight engagement pings while the page is visible.

**Lightweight**

* Tracker script is about 2 KB, loaded with `defer`, sent with `navigator.sendBeacon`.
* Works with page caching plugins – no nonces in the front-end.
* Two compact database tables, automatic cleanup of old data.
* The dashboard is plain JavaScript with built-in SVG charts – no libraries.

**Privacy**

* No cookies. A random anonymous ID is kept in the visitor's localStorage to tell new visitors from returning ones.
* IP addresses are not stored.
* All data stays in your own database and is never sent anywhere.
* Optional respect for the browser Do Not Track signal.
* Suggested text is added to the WordPress Privacy Policy guide.

== Installation ==

1. Upload the `visitrix` folder to `/wp-content/plugins/` or install the plugin from the Plugins screen.
2. Activate the plugin.
3. Open **Statistics** in the admin menu. Data appears after the first visits.
4. Optional: adjust excluded roles and data retention in **Statistics → Settings**.

== Frequently Asked Questions ==

= Are my own visits counted? =

By default visits from logged-in administrators are not tracked. You can choose excluded roles in the settings.

= Does it work with caching plugins? =

Yes. The tracker is a static script that sends data to the REST API, so cached pages are counted correctly.

= Do I need a cookie banner? =

Visitrix does not set cookies and does not store IP addresses, but it does store an anonymous identifier in localStorage. Requirements differ by country – please consult your legal adviser.

= Why are numbers different from Google Analytics or Yandex Metrica? =

Every tool filters bots and counts sessions slightly differently. Visitrix counts only real browsers that execute JavaScript, and ad blockers usually do not block a first-party script.

= Who can see the statistics? =

Users with the `manage_options` capability. Developers can change it with the `visitrix_view_capability` filter.

= Developer hooks =

* `visitrix_should_track` – return false to skip tracking on the current page.
* `visitrix_source_rules` – add or change search engines, social networks and messengers.
* `visitrix_view_capability` – capability required to view reports.

== Screenshots ==

1. Traffic summary with key metrics and chart.
2. Traffic sources, devices, search engines and browsers.
3. Sources report and popular pages.
4. Settings.

== Changelog ==

= 1.1.0 =
* New: "New and returning visitors" report and chart line.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
Adds the new and returning visitors report.

= 1.0.0 =
Initial release.
