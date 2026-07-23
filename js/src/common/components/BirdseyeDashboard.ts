import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import type { ComponentAttrs } from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import m from 'mithril';
import type Mithril from 'mithril';

interface ListRow {
  label: string;
  visits: number;
  /** Forum-relative link to the content this row describes (top pages,
   *  discussions, tags, searches, needs-a-reply). Absent when there's
   *  nothing to open (sources, devices, countries). */
  url?: string;
}

interface RangeBlock {
  totals: {
    visits: number;
    pageviews: number;
    bounce_rate: number | null;
    avg_session_sec: number | null;
    posts: number;
    registrations: number;
  };
  timeseries: { date: string; visits: number; pageviews: number }[];
  pages: ListRow[];
  discussions: ListRow[];
  sources: ListRow[];
  devices: ListRow[];
  locations: ListRow[];
  searches: ListRow[];
  /** null when the tags extension isn't installed — the card is dropped. */
  tags: ListRow[] | null;
}

interface TodayBlock {
  visits: number;
  pageviews: number;
  posts: number;
  registrations: number;
}

interface ActivationRow {
  week: string;
  joined: number;
  converted: number;
  pct: number | null;
}

interface StatsPayload {
  ranges: Record<string, RangeBlock>;
  today: TodayBlock;
  activation: ActivationRow[];
  unanswered: ListRow[];
}

export interface BirdseyeDashboardAttrs extends ComponentAttrs {
  /** Skip the built-in heading (the range switcher stays) — for hosts that already provide a title, like the forum modal. */
  hideTitle?: boolean;
}

/**
 * The analytics dashboard — rendered on the extension's admin page beneath
 * its settings, and inside the forum's Analytics modal for groups holding
 * the viewStats permission. Reads local rollups via GET /api/birdseye/stats;
 * the world map SVG is fetched once and shaded per range.
 */
export default class BirdseyeDashboard extends Component<BirdseyeDashboardAttrs> {
  loading = true;
  ranges: Record<string, RangeBlock> | null = null;
  today: TodayBlock | null = null;
  activation: ActivationRow[] = [];
  unanswered: ListRow[] = [];
  range = '30d';
  /** How the categorical breakdown cards render — ranked bars or a pie/donut.
   *  Toggled from the header; applies to every list at once. */
  viewMode: 'bars' | 'pie' = 'bars';
  mapMarkup: string | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    const api = app.forum.attribute('apiUrl');

    app
      .request<StatsPayload>({ method: 'GET', url: `${api}/birdseye/stats` })
      .then((data) => {
        this.ranges = data.ranges;
        this.today = data.today;
        this.activation = data.activation || [];
        this.unanswered = data.unanswered || [];
      })
      .finally(() => {
        this.loading = false;
        m.redraw();
      });

    // Plain fetch, NOT app.request: the response is SVG text, and Flarum's
    // request wrapper treats any non-JSON body as an error (in debug mode it
    // throws the raw response up in a modal). Same-origin cookies carry the
    // admin session; GETs need no CSRF token.
    fetch(`${api}/birdseye/world-map`, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then((r) => (r.ok ? r.text() : Promise.reject(new Error(String(r.status)))))
      .then((svg) => {
        this.mapMarkup = svg;
        m.redraw();
      })
      .catch(() => {
        // No map — the dashboard renders everything else regardless.
      });
  }

  view() {
    if (this.loading) {
      return m('.BirdseyeDashboard', m(LoadingIndicator));
    }

    const block = this.ranges?.[this.range];

    if (!block) {
      return m('.BirdseyeDashboard', m('p.helpText', trans('no_data')));
    }

    const t = block.totals;

    return m('.BirdseyeDashboard', [
      m('.BirdseyeDashboard-header', [
        this.attrs.hideTitle ? m('span') : m('h3', trans('title')),
        m('.BirdseyeDashboard-controls', [
          // Bars ⇄ pie: flips every categorical breakdown card at once.
          m(
            '.BirdseyeDashboard-toggle',
            (['bars', 'pie'] as const).map((mode) =>
              m(
                'button.Button.Button--size-sm',
                {
                  className: this.viewMode === mode ? 'Button--primary' : '',
                  onclick: () => (this.viewMode = mode),
                },
                trans(mode === 'bars' ? 'view_bars' : 'view_pie')
              )
            )
          ),
          m(
            '.BirdseyeDashboard-ranges',
            ['7d', '30d'].map((r) =>
              m(
                'button.Button.Button--size-sm',
                {
                  className: this.range === r ? 'Button--primary' : '',
                  onclick: () => (this.range = r),
                },
                trans(r === '7d' ? 'range_7d' : 'range_30d')
              )
            )
          ),
        ]),
      ]),

      m('.BirdseyeDashboard-tiles', [
        this.tile(trans('visitors'), String(t.visits)),
        this.tile(trans('pageviews'), String(t.pageviews)),
        this.tile(trans('bounce_rate'), t.bounce_rate === null ? '—' : `${Math.round(t.bounce_rate * 100)}%`),
        this.tile(trans('avg_visit'), t.avg_session_sec === null ? '—' : fmtDur(t.avg_session_sec)),
        this.tile(trans('posts'), String(t.posts)),
        this.tile(trans('new_members'), String(t.registrations)),
      ]),

      this.todayStrip(),

      this.chart(block),

      m('.BirdseyeDashboard-lists', [
        this.card(trans('top_pages'), block.pages, (l) => l || '/'),
        this.card(trans('top_discussions'), block.discussions, (l) => l),
        block.tags === null ? null : this.card(trans('tags'), block.tags, (l) => l),
        this.card(trans('unanswered'), this.unanswered, (l) => l, trans('unanswered_note')),
        this.card(trans('sources'), block.sources, (l) => l || transText('direct')),
        this.card(trans('searches'), block.searches, (l) => l),
        this.card(trans('devices'), block.devices, (l) => (l ? l.charAt(0).toUpperCase() + l.slice(1) : transText('unknown'))),
        this.card(trans('countries'), block.locations.slice(0, 8), countryName),
        this.activationCard(),
      ]),

      m('.BirdseyeDashboard-card', [
        m('.BirdseyeDashboard-cardTitle', trans('world')),
        m('.BirdseyeDashboard-map', {
          oncreate: (v: Mithril.VnodeDOM) => this.mountMap(v.dom as HTMLElement),
          onupdate: (v: Mithril.VnodeDOM) => this.mountMap(v.dom as HTMLElement),
        }),
      ]),
    ]);
  }

  tile(label: Mithril.Children, value: string) {
    return m('.BirdseyeDashboard-tile', [m('.BirdseyeDashboard-tileLabel', label), m('.BirdseyeDashboard-tileValue', value)]);
  }

  chart(block: RangeBlock) {
    const ts = block.timeseries;
    const max = Math.max(...ts.map((p) => p.visits), 1);

    return m('.BirdseyeDashboard-card', [
      m('.BirdseyeDashboard-cardTitle', [
        trans('visitors_per_day'),
        m('span.BirdseyeDashboard-span', ts.length ? `${ts[0].date} – ${ts[ts.length - 1].date}` : ''),
      ]),
      m(
        '.BirdseyeDashboard-chart',
        ts.map((p) =>
          // data-label feeds the pure-CSS hover tooltip (instant, styled) —
          // native title tooltips are delayed enough to read as missing.
          m('.BirdseyeDashboard-chartCol', { 'data-label': `${shortDate(p.date)} · ${p.visits}` }, [
            m('.BirdseyeDashboard-chartBar', {
              style: { height: `${Math.max(Math.round((p.visits / max) * 100), p.visits ? 2 : 0)}%` },
            }),
          ])
        )
      ),
    ]);
  }

  /** Render a categorical breakdown as ranked bars or as a pie, per the
   *  header toggle. Same data, same links — only the shape changes. */
  card(title: Mithril.Children, rows: ListRow[], labeller: (l: string) => Mithril.Children, note?: Mithril.Children) {
    return this.viewMode === 'pie' ? this.donut(title, rows, labeller, note) : this.list(title, rows, labeller, note);
  }

  /** Wrap a row label in a link to the content it describes, when the server
   *  supplied a target. Opens in a new tab so drilling into a discussion or
   *  tag never throws away the dashboard you were reading.
   *
   *  Security: `url` for a "page" row is a captured request path, so treat it
   *  as untrusted. Only link a genuine same-origin absolute path ("/d/5",
   *  "/?q=x") — never a scheme or a protocol-relative "//host" that could
   *  navigate off-site if the forum base ever resolved empty. */
  maybeLink(content: Mithril.Children, url?: string): Mithril.Children {
    if (!url || !/^\/(?:$|[^/].*)$/.test(url)) return content;
    const base = (app.forum.attribute('baseUrl') as string) || '';
    return m('a', { href: base + url, target: '_blank', rel: 'noopener' }, content);
  }

  list(title: Mithril.Children, rows: ListRow[], labeller: (l: string) => Mithril.Children, note?: Mithril.Children) {
    const max = Math.max(...rows.map((r) => r.visits), 1);

    return m('.BirdseyeDashboard-card', [
      m('.BirdseyeDashboard-cardTitle', [title, note ? m('span.BirdseyeDashboard-span', note) : null]),
      rows.length
        ? rows.map((r) =>
            m('.BirdseyeDashboard-row', [
              m('.BirdseyeDashboard-rowBar', { style: { width: `${Math.round((r.visits / max) * 100)}%` } }),
              m('span.BirdseyeDashboard-rowLabel', this.maybeLink(labeller(r.label), r.url)),
              m('span.BirdseyeDashboard-rowValue', String(r.visits)),
            ])
          )
        : m('p.helpText', trans('no_data')),
    ]);
  }

  /**
   * The pie view of a breakdown: a donut with a value/percentage legend, the
   * pie option asked for on discuss.flarum.org. Any list can render this way
   * via the header toggle; long-tail rankings simply fold everything past the
   * palette into a single "Other" wedge so the ring stays readable. Pure CSS:
   * a conic-gradient ring with a punched-out centre holding the total. Legend
   * labels stay linked wherever the bar list would be.
   */
  donut(title: Mithril.Children, rows: ListRow[], labeller: (l: string) => Mithril.Children, note?: Mithril.Children) {
    const cardTitle = m('.BirdseyeDashboard-cardTitle', [title, note ? m('span.BirdseyeDashboard-span', note) : null]);
    const present = rows.filter((r) => r.visits > 0);
    const total = present.reduce((s, r) => s + r.visits, 0);

    if (!total) {
      return m('.BirdseyeDashboard-card', [cardTitle, m('p.helpText', trans('no_data'))]);
    }

    // Keep the largest slices distinct; fold the rest into one grey wedge so
    // the ring never fragments into unreadable slivers.
    const max = DONUT_PALETTE.length;
    let head = present;
    let otherTotal = 0;
    if (present.length > max) {
      head = present.slice(0, max - 1);
      otherTotal = present.slice(max - 1).reduce((s, r) => s + r.visits, 0);
    }

    const slices: { label: Mithril.Children; visits: number; color: string; url?: string }[] = head.map((r, i) => ({
      label: labeller(r.label),
      visits: r.visits,
      color: DONUT_PALETTE[i],
      url: r.url,
    }));
    if (otherTotal > 0) slices.push({ label: transText('other'), visits: otherTotal, color: DONUT_OTHER });

    // Cumulative conic-gradient stops. Each slice paints from where the last
    // one ended to its running total, as a percentage of the whole.
    let acc = 0;
    const stops = slices
      .map((s) => {
        const start = (acc / total) * 100;
        acc += s.visits;
        const end = (acc / total) * 100;
        return `${s.color} ${start}% ${end}%`;
      })
      .join(', ');

    return m('.BirdseyeDashboard-card', [
      cardTitle,
      m('.BirdseyeDashboard-donutWrap', [
        m('.BirdseyeDashboard-donut', { style: { background: `conic-gradient(${stops})` } }, [
          m('.BirdseyeDashboard-donutHole', [
            m('.BirdseyeDashboard-donutTotal', String(total)),
            m('.BirdseyeDashboard-donutTotalLabel', transText('visitors_word')),
          ]),
        ]),
        m(
          '.BirdseyeDashboard-legend',
          slices.map((s) =>
            m('.BirdseyeDashboard-legendRow', [
              m('span.BirdseyeDashboard-legendSwatch', { style: { background: s.color } }),
              m('span.BirdseyeDashboard-legendLabel', this.maybeLink(s.label, s.url)),
              m('span.BirdseyeDashboard-legendValue', `${s.visits} · ${Math.round((s.visits / total) * 100)}%`),
            ])
          )
        ),
      ]),
    ]);
  }

  /**
   * Today's counts, straight from the capture buffer — the rollups above
   * only cover finished days, so this is the "is it working right now" row.
   */
  todayStrip() {
    const d = this.today;

    if (!d) return null;

    const stat = (label: Mithril.Children, value: number) =>
      m('.BirdseyeDashboard-todayStat', [m('.BirdseyeDashboard-todayValue', String(value)), m('.BirdseyeDashboard-todayLabel', label)]);

    return m('.BirdseyeDashboard-card', [
      m('.BirdseyeDashboard-cardTitle', [trans('today'), m('span.BirdseyeDashboard-span', trans('today_note'))]),
      m('.BirdseyeDashboard-todayStats', [
        stat(trans('visitors'), d.visits),
        stat(trans('pageviews'), d.pageviews),
        stat(trans('posts'), d.posts),
        stat(trans('new_members'), d.registrations),
      ]),
    ]);
  }

  /**
   * Weekly lurker→poster cohorts: bar = share of that week's new members
   * who posted within 7 days of joining.
   */
  activationCard() {
    const rows = this.activation;
    const anyJoined = rows.some((w) => w.joined > 0);

    return m('.BirdseyeDashboard-card', [
      m('.BirdseyeDashboard-cardTitle', trans('activation')),
      anyJoined
        ? rows.map((w) =>
            m('.BirdseyeDashboard-row', [
              m('.BirdseyeDashboard-rowBar', { style: { width: `${Math.round((w.pct || 0) * 100)}%` } }),
              m('span.BirdseyeDashboard-rowLabel', `${shortDate(w.week)} · ${w.joined} ${transText('joined_word')}`),
              m('span.BirdseyeDashboard-rowValue', w.pct === null ? '—' : `${Math.round(w.pct * 100)}%`),
            ])
          )
        : m('p.helpText', trans('no_data')),
      anyJoined ? m('p.helpText.BirdseyeDashboard-note', trans('activation_help')) : null,
    ]);
  }

  /**
   * Insert the SVG once, then (re)shade on every render — mirrors the
   * original site dashboard: accent alpha scaled to the range max, hover
   * tooltip with count and share.
   */
  mountMap(el: HTMLElement) {
    if (!this.mapMarkup) return;

    if (!el.querySelector('svg')) {
      el.innerHTML = this.mapMarkup;
      el.style.position = 'relative';

      const tip = document.createElement('div');
      tip.className = 'BirdseyeDashboard-mapTip';
      el.appendChild(tip);

      const svg = el.querySelector('svg')!;
      svg.querySelectorAll('path').forEach((p) => {
        const t = p.querySelector('title');
        if (t) {
          (p as SVGPathElement).dataset.name = t.textContent ?? '';
          t.remove();
        }
      });

      svg.addEventListener('pointermove', (e) => {
        const p = (e.target as Element).closest('path') as SVGPathElement | null;
        if (!p) {
          tip.style.display = 'none';
          return;
        }
        const v = parseInt(p.dataset.v || '0', 10);
        tip.textContent = '';
        tip.appendChild(Object.assign(document.createElement('strong'), { textContent: p.dataset.name || '' }));
        tip.appendChild(document.createElement('br'));
        tip.appendChild(document.createTextNode(v ? `${v} ${transText('visitors_word')} · ${p.dataset.p}%` : transText('no_visitors')));
        tip.style.display = 'block';
        const r = el.getBoundingClientRect();
        const x = e.clientX - r.left;
        const flip = x > r.width / 2;
        tip.style.left = flip ? '' : `${x + 14}px`;
        tip.style.right = flip ? `${r.width - x + 14}px` : '';
        tip.style.top = `${e.clientY - r.top + 12}px`;
      });
      svg.addEventListener('pointerleave', () => (tip.style.display = 'none'));
    }

    const svg = el.querySelector('svg');
    if (!svg) return;

    const rows = this.ranges?.[this.range]?.locations ?? [];
    const total = this.ranges?.[this.range]?.totals.visits ?? 0;
    const counts: Record<string, number> = {};
    rows.forEach((r) => (counts[r.label] = r.visits));
    const max = Math.max(...rows.map((r) => r.visits), 1);

    svg.querySelectorAll('path').forEach((p) => {
      const v = counts[p.getAttribute('data-c') || ''] || 0;
      const el2 = p as SVGPathElement;
      if (v) {
        el2.style.fill = `rgba(30,195,214,${(0.25 + 0.6 * (v / max)).toFixed(2)})`;
        el2.dataset.v = String(v);
        el2.dataset.p = String(total ? Math.round((v / total) * 1000) / 10 : 0);
      } else {
        el2.style.fill = '';
        delete el2.dataset.v;
        delete el2.dataset.p;
      }
    });
  }
}

// Categorical donut palette — the brand cyan leads, then hues chosen to stay
// distinct on both the light and dark Flarum themes. Overflow slices fold into
// the grey "Other" wedge.
const DONUT_PALETTE = ['#1ec3d6', '#6c8cff', '#ffb020', '#f2617a', '#34c759', '#a06cff'];
const DONUT_OTHER = '#8a97a6';

// lib.* keys ship to both frontends (admin.*/forum.* are filtered per-bundle).
function trans(key: string): Mithril.Children {
  return app.translator.trans(`linkrobins-birdseye.lib.dashboard.${key}`);
}

function transText(key: string): string {
  return String(app.translator.trans(`linkrobins-birdseye.lib.dashboard.${key}`));
}

function shortDate(iso: string): string {
  try {
    return new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  } catch {
    return iso;
  }
}

function fmtDur(sec: number): string {
  sec = Math.round(sec || 0);
  return sec < 60 ? `${sec}s` : `${Math.floor(sec / 60)}m ${sec % 60}s`;
}

function countryName(code: string): string {
  if (!code) return transText('unknown');
  try {
    return new Intl.DisplayNames(undefined, { type: 'region' }).of(code) || code;
  } catch {
    return code;
  }
}
