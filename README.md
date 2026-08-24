# Link Robins Birdseye

Forum analytics for [Flarum](https://flarum.org) — captured server-side, with
**zero JavaScript, no cookies, and no external tracker**. Your analytics
history lives in **your own database** and stays there.

## How it works

The extension records events (page and discussion views, searches, posts,
registrations) on the server as requests happen. Visitors are counted with a
daily-rotating salted hash — no IP address is ever stored, no cookie is ever
set, and nothing runs in your visitors' browsers.

Events sit in a short-lived local buffer (72 hours max). Once a day, the
buffer is rolled up into small per-day aggregate rows in your database —
that's the permanent history, a few kilobytes per month.

Everything is computed **on your own server**: pageviews, visitors, bounce
rate, session length, top pages and discussions, referrer sources, devices,
countries (world map included). There is no account, no license key, and no
external service — nothing ever leaves your forum, and the full picture is
free.

Visitor countries come from your proxy's country header when you have one
(Cloudflare's `CF-IPCountry` by default). Without a proxy, point the optional
country-database setting at a MaxMind GeoLite2-Country file and lookups
happen locally too.

## Beyond the numbers

- **Forum health, not just traffic** — new members per day, discussions that
  get read but never answered, top tags, live search terms, and a "today so
  far" strip straight from the buffer.
- **Share the dashboard** — a `View forum analytics` permission lets any
  group you choose open the dashboard from their user menu, without admin
  access. Nobody holds it until you grant it.
- **Weekly digest** — every Monday, administrators get a plain-text email
  summary of last week vs the week before. One switch to turn off.

## Installation

```sh
composer require linkrobins/birdseye
```

Enable it in the admin panel. That's it — stats begin collecting immediately.

## Requirements

- Flarum `1.8` or `2.x`, PHP `>= 8.0`
- The [scheduler](https://docs.flarum.org/scheduler) must be running for
  daily rollups (`php flarum schedule:run` via cron).

## Privacy notes

- Visitor identity is `hash(secret + date + IP + user agent)`, truncated;
  the salt rotates daily so visitors cannot be tracked across days.
- Country detection uses a trusted proxy country header when one is
  configured — Cloudflare's `CF-IPCountry` by default; point the *Trusted
  country header* setting at e.g. `X-Country` if nginx-geoip supplies it.
  The header is trusted as-is, so if your forum is **not** behind such a
  proxy, blank the setting (a client talking to your server directly could
  otherwise forge its country). Without a header, an **anonymized IP
  prefix** (/24 for IPv4, /48 for IPv6 — never the full address) is kept in
  the 72-hour buffer solely for country lookup during processing, then
  discarded. This can be disabled in settings, and no full IP is written to
  disk either way.
- Bounce rate and visit duration are measured log-style (server-side), which
  runs slightly conservative compared to script-based trackers — the cost of
  shipping no JavaScript at all.

## Development

The frontend deliberately builds with a bare webpack + babel config instead
of `flarum-webpack-config`. One artifact serves Flarum 1.8 **and** 2.x, and
flarum-webpack-config's module prologue references `flarum.reg`, which does
not exist on 1.x — a bundle built with it dies on load there. Details are in
the header of `js/webpack.config.js`; please don't "modernize" the build
without checking the 1.8 leg of CI.

## Links

- [Support](https://github.com/linkrobins/birdseye/issues)
- [Source on GitHub](https://github.com/linkrobins/birdseye)
