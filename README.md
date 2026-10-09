# Link Robins Birdseye

Forum analytics for [Flarum](https://flarum.org), captured server-side, with **zero JavaScript, no cookies, and no external tracker**. Your analytics history lives in **your own database** and stays there.

## How it works

The extension records events (page and discussion views, searches, posts, registrations) on the server as requests happen. Visitors are counted with a daily-rotating salted hash: no IP address is ever stored, no cookie is ever set, and nothing runs in your visitors' browsers.

Events sit in a short-lived local buffer (72 hours max). Once a day, the buffer is rolled up into small per-day aggregate rows in your database. That is the permanent history, a few kilobytes per month.

Everything is computed **on your own server**: pageviews, visitors, bounce rate, session length, top pages and discussions, referrer sources, devices, countries (world map included). There is no account, no license key, and no external service. Nothing ever leaves your forum, and the full picture is free.

## Beyond the numbers

- **Forum health, not just traffic.** New members per day, discussions that get read but never answered, top tags, live search terms, and a "today so far" strip straight from the buffer.
- **Share the dashboard.** A `View forum analytics` permission lets any group you choose open the dashboard from their user menu, without admin access. Nobody holds it until you grant it.
- **Weekly digest.** Every Monday, administrators get a plain-text email summary of last week against the week before. One switch to turn off.
- **History by month and year,** and a CSV export of every card.

## Visitor countries

Birdseye finds a visitor's country on your own server, in one of three ways.

**Behind Cloudflare or another proxy that sends a country header:** nothing to do. Birdseye reads Cloudflare's `CF-IPCountry` header by default. For another proxy, enter its header name (for example `X-Country` from nginx-geoip) in the **Trusted country header** setting.

**Everywhere else (most shared hosting): also nothing to do.** Birdseye downloads [DB-IP](https://db-ip.com)'s free country database into Flarum's `storage` folder and refreshes it once a month. It needs no account or key, and every lookup happens on your server: only the database file is downloaded, and no visitor data is sent anywhere. This needs Flarum's scheduler running (a cron job calling `php flarum schedule:run` every minute), which Birdseye already uses for its stats. To fetch the database straight away instead of waiting for the next hourly check, run `php flarum birdseye:country-database`. When it is in use, the dashboard shows the "IP Geolocation by DB-IP" credit its licence (CC BY 4.0) asks for.

If your host blocks outgoing connections from PHP, the download fails and the world map says so, with the error. Ask your host to allow HTTPS to `download.db-ip.com`, or use your own database file as below. You can also turn the download off with **Download a country database automatically**.

**Your own database file (optional):** to use MaxMind's GeoLite2 Country database, or any other MaxMind-format country database, instead:

1. Create a free account at [maxmind.com](https://www.maxmind.com/en/geolite2/signup) and download **GeoLite2 Country** in the **GZIP** (`.mmdb`) format.
2. Extract the download. It is a `.tar.gz` archive; the file you need inside it is `GeoLite2-Country.mmdb`.
3. Upload that file to your server, ideally **outside your public web folder** (outside `public_html` or similar), so it can't be downloaded from your site. For example `/home/you/geoip/GeoLite2-Country.mmdb`.
4. Make sure PHP can read it. Permissions of `644` usually work.
5. In Birdseye's settings, enter the **full path** to the file (starting with `/`) in **Country database file**, keep **Country lookup via anonymized IP prefix** turned on, and leave **Trusted country header** empty.

A file set this way always takes the place of the automatic download. MaxMind updates its database regularly; replacing the file now and then keeps countries accurate.

Countries appear from the next day processed onwards. Days already summarised can't be filled in afterwards, because the raw events behind them are deleted once their totals are written.

If something is wrong, the world map says what: the download failing or not having run yet, a path that isn't a full path, a folder instead of the file, no file at that path, a file PHP can't read, the still-compressed download, or a file that isn't a MaxMind database.

## Installation

```sh
composer require linkrobins/birdseye
```

Enable it in the admin panel. Stats begin collecting immediately.

## Requirements

- Flarum `2.x`, PHP `8.3` or newer
- The [scheduler](https://docs.flarum.org/scheduler) must be running for daily rollups (`php flarum schedule:run` via cron).

## Privacy notes

- Visitor identity is `hash(secret + date + IP + user agent)`, truncated; the salt rotates daily so visitors cannot be tracked across days.
- Country detection uses a trusted proxy country header when one is configured: Cloudflare's `CF-IPCountry` by default, or e.g. `X-Country` if nginx-geoip supplies it. The header is trusted as-is, so if your forum is **not** behind such a proxy, blank the setting (a client talking to your server directly could otherwise forge its country).
- Without a header, an **anonymized IP prefix** (/24 for IPv4, /48 for IPv6, never the full address) is kept in the 72-hour buffer solely for country lookup during processing, then discarded. This can be turned off in settings, and no full IP is written to disk either way.
- The only outgoing request Birdseye makes is the monthly download of DB-IP's country database, which carries no visitor data. Turn it off in settings if you supply countries another way.
- Bounce rate and visit duration are measured log-style (server-side), which runs slightly conservative compared to script-based trackers. That is the cost of shipping no JavaScript at all.

## Links

- [Support](https://github.com/linkrobins/birdseye/issues)
- [Source on GitHub](https://github.com/linkrobins/birdseye)
