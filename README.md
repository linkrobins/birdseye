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

- **Without a license key**, rollups are computed locally and include basic
  daily counts: pageviews, visitors, posts, registrations. Nothing ever
  leaves your server.
- **With a [Birdseye subscription](https://linkrobins.com/birdseye)**, the
  daily batch is sent to our processor, which computes the full picture —
  bounce rate, session length, top pages and discussions, referrer sources,
  devices, countries (world map included) — and returns the results to be
  stored **in your database**. We keep nothing. Cancel anytime; every stat
  you've collected stays with you.

## Installation

```sh
composer require linkrobins/birdseye
```

Enable it in the admin panel. That's it — stats begin collecting immediately.

## Requirements

- Flarum `^2.0`, PHP `^8.3`
- The [scheduler](https://docs.flarum.org/scheduler) must be running for
  daily rollups (`php flarum schedule:run` via cron).

## Privacy notes

- Visitor identity is `hash(secret + date + IP + user agent)`, truncated;
  the salt rotates daily so visitors cannot be tracked across days.
- Country detection uses your proxy's country header (e.g. Cloudflare) when
  available. Otherwise, an **anonymized IP prefix** (/24 for IPv4, /48 for
  IPv6 — never the full address) is kept in the 72-hour buffer solely for
  country lookup during processing, then discarded. This can be disabled in
  settings, and no full IP is written to disk either way.
- Bounce rate and visit duration are measured log-style (server-side), which
  runs slightly conservative compared to script-based trackers — the cost of
  shipping no JavaScript at all.

## Links

- [Birdseye subscriptions](https://linkrobins.com/birdseye)
- [Support forum](https://linkrobins.com/forum)
- [Source on GitHub](https://github.com/linkrobins/birdseye)
