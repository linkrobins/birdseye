import type Mithril from 'mithril';
import app from 'flarum/common/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';

// Mithril is the global Flarum exposes, and core's own JSX compiles to these
// same `m(...)` calls. Deliberately not imported: flarum-webpack-config does
// not externalize mithril, so an import would bundle a second copy of it.
const m = (window as any).m;

interface ListRow {
  label: string;
  visits: number;
  /** Forum-relative link to the content this row describes (top pages,
   *  discussions, tags, searches, needs-a-reply). Absent when there's
   *  nothing to open (sources, devices, countries). */
  url?: string;
}

/** What a breakdown card's numbers count — drives the donut centre word and
 *  the per-card CSV header. Sources, devices, and countries are distinct
 *  visitors (the processor counts one person once per key); content cards
 *  count views; the searches card counts search submissions. Before v1.3.1
 *  every card said "visitors", which mislabelled the view-counted cards
 *  (discuss.flarum.org d/39605/19). */
type Unit = 'visitors' | 'views' | 'searches' | 'members';

const UNIT_COL: Record<Unit, string> = {
  visitors: 'col_visitors',
  views: 'col_views',
  searches: 'col_searches',
  members: 'col_members',
};

/** The two header choices, and what the dashboard opens on the first time. */
const RANGES = ['7d', '30d'] as const;
const VIEW_MODES = ['bars', 'pie'] as const;
type RangeKey = (typeof RANGES)[number];
type ViewMode = (typeof VIEW_MODES)[number];
const DEFAULT_RANGE: RangeKey = '30d';
const DEFAULT_VIEW_MODE: ViewMode = 'bars';

/** How far the expanded map zooms in. Eight times fit-width is enough to read
 *  the small island states that vanish at card size (d/39605/41). */
const MAP_MAX_ZOOM = 8;
/** Per click of the zoom buttons, and per double-click on the expanded map. */
const MAP_ZOOM_STEP = 1.6;
/** How far a finger may travel and still count as a tap on a country rather
 *  than a pan. A held finger drifts a pixel or two on its own. */
const MAP_TAP_SLOP = 8;

// Sticky header choices live in localStorage, not in a user preference: they
// are per-screen viewing state, and the dashboard makes no write requests.
// Reads and writes are both guarded — Safari's private mode and "block all
// storage" throw on access rather than returning null, and a dashboard must
// never fail to render over a remembered button.
const PREF_PREFIX = 'linkrobins-birdseye.';

function readPref<T extends string>(key: string, allowed: readonly T[], fallback: T): T {
  try {
    const stored = window.localStorage.getItem(PREF_PREFIX + key) as T | null;
    // Validate against the live option list: a stale value left by an older
    // version (or a hand-edited key) must not strand the dashboard.
    return stored !== null && allowed.includes(stored) ? stored : fallback;
  } catch {
    return fallback;
  }
}

function writePref(key: string, value: string): void {
  try {
    window.localStorage.setItem(PREF_PREFIX + key, value);
  } catch {
    // Storage unavailable or full — the choice still applies for this view.
  }
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
  /** Confirmed members per day, newest first; label is an ISO date. */
  new_members: ListRow[];
}

interface TodayBlock {
  visits: number;
  pageviews: number;
  posts: number;
  registrations: number;
}

interface PeriodRow {
  month?: string;
  year?: string;
  visits: number;
  pageviews: number;
  bounce_rate: number | null;
  avg_session_sec: number | null;
  posts: number;
  registrations: number;
  partial: boolean;
}

interface StatsPayload {
  ranges: Record<string, RangeBlock>;
  months: PeriodRow[];
  years: PeriodRow[];
  today: TodayBlock;
  unanswered: ListRow[];
}

export interface BirdseyeDashboardAttrs {
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
  months: PeriodRow[] = [];
  years: PeriodRow[] = [];
  /** Which calendar the history card shows; years only exist to click once
   *  the rollups span more than one. */
  historyUnit: 'month' | 'year' = 'month';
  today: TodayBlock | null = null;
  unanswered: ListRow[] = [];
  /** Both header choices are sticky — picked once, then restored on every
   *  later visit rather than re-selected each time (d/39605/26). */
  range: RangeKey = readPref('range', RANGES, DEFAULT_RANGE);
  /** How the categorical breakdown cards render — ranked bars or a pie/donut.
   *  Toggled from the header; applies to every list at once. */
  viewMode: ViewMode = readPref('viewMode', VIEW_MODES, DEFAULT_VIEW_MODE);
  mapMarkup: string | null = null;

  /** Whether the world map has taken over the screen. At card size the map is
   *  a couple of hundred pixels tall, which is where small countries and
   *  islands disappear (d/39605/41). */
  mapExpanded = false;
  /** Zoom and pan of the expanded map: 1 = fitted, offsets in CSS pixels. */
  mapScale = 1;
  mapX = 0;
  mapY = 0;
  /** The map container, kept so the controls can reach the SVG. */
  mapEl: HTMLElement | null = null;
  /** Pointers currently down on the map: one drags, two pinch. */
  mapPointers = new Map<number, { x: number; y: number }>();
  mapDrag: { x: number; y: number } | null = null;
  mapPinch: { dist: number; cx: number; cy: number } | null = null;
  /** The country tooltip, reachable from both the hover and the tap paths. */
  mapTip: HTMLElement | null = null;
  /** The country the tooltip is currently naming, kept lit while it is up. */
  mapTipPath: SVGPathElement | null = null;
  /** The single pointer that might turn out to be a tap: where it is now, and
   *  how far it has travelled since it went down. */
  mapTap: { id: number; x: number; y: number; moved: number } | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    const api = app.forum.attribute('apiUrl');

    app
      .request<StatsPayload>({ method: 'GET', url: `${api}/birdseye/stats` })
      .then((data) => {
        this.ranges = data.ranges;
        this.months = data.months || [];
        this.years = data.years || [];
        this.today = data.today;
        this.unanswered = data.unanswered || [];

        // A remembered range the payload doesn't carry would render an empty
        // dashboard forever, with no hint that the stored choice is the
        // cause. Fall back to the default instead.
        if (!this.ranges?.[this.range] && this.ranges?.[DEFAULT_RANGE]) {
          this.range = DEFAULT_RANGE;
        }
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
            VIEW_MODES.map((mode) =>
              m(
                'button.Button.Button--size-sm',
                {
                  // type=button: inside the forum modal the dashboard sits in
                  // a <form>, so an untyped button submits it (closing the
                  // modal + reloading) instead of just toggling the view.
                  type: 'button',
                  className: this.viewMode === mode ? 'Button--primary' : '',
                  onclick: () => writePref('viewMode', (this.viewMode = mode)),
                },
                trans(mode === 'bars' ? 'view_bars' : 'view_pie')
              )
            )
          ),
          m(
            '.BirdseyeDashboard-ranges',
            RANGES.map((r) =>
              m(
                'button.Button.Button--size-sm',
                {
                  type: 'button',
                  className: this.range === r ? 'Button--primary' : '',
                  onclick: () => writePref('range', (this.range = r)),
                },
                trans(r === '7d' ? 'range_7d' : 'range_30d')
              )
            )
          ),
          m('button.Button.Button--size-sm.BirdseyeDashboard-exportAll', { type: 'button', onclick: () => this.exportAll() }, [
            m('i.fas.fa-download'),
            ' ',
            trans('export_all'),
          ]),
        ]),
      ]),

      m('.BirdseyeDashboard-tiles', [
        this.tile(trans('visitors'), String(t.visits)),
        this.tile(trans('pageviews'), String(t.pageviews)),
        this.tile(trans('bounce_rate'), t.bounce_rate === null ? '—' : `${Math.round(t.bounce_rate * 100)}%`),
        this.tile(trans('avg_visit'), t.avg_session_sec === null ? '—' : fmtDur(t.avg_session_sec)),
        this.tile(trans('posts'), String(t.posts)),
        this.tile(trans('signups'), String(t.registrations)),
      ]),

      this.todayStrip(),

      this.chart(block),

      m('.BirdseyeDashboard-lists', [
        this.card('pages', trans('top_pages'), block.pages, (l) => l || '/', 'views'),
        this.card('discussions', trans('top_discussions'), block.discussions, (l) => l, 'views'),
        block.tags === null ? null : this.card('tags', trans('tags'), block.tags, (l) => l, 'views'),
        this.card('unanswered', trans('unanswered'), this.unanswered, (l) => l, 'views', trans('unanswered_note')),
        this.card('sources', trans('sources'), block.sources, (l) => l || transText('direct'), 'visitors'),
        this.card('searches', trans('searches'), block.searches, (l) => l, 'searches'),
        this.card('devices', trans('devices'), block.devices, (l) => (l ? l.charAt(0).toUpperCase() + l.slice(1) : transText('unknown')), 'visitors'),
        this.card('countries', trans('countries'), block.locations.slice(0, 8), countryName, 'visitors'),
        this.card('new_members', trans('new_members'), block.new_members, shortDate, 'members'),
      ]),

      this.historyCard(),

      m(
        '.BirdseyeDashboard-card.BirdseyeDashboard-mapCard',
        // A class rather than a second selector: changing the selector would
        // make Mithril replace the element, throwing away the mounted SVG and
        // its listeners every time the map is expanded or closed.
        { className: this.mapExpanded ? 'is-expanded' : '' },
        [
          m('.BirdseyeDashboard-cardTitle', [
            m('span.BirdseyeDashboard-cardTitleText', trans('world')),
            m('.BirdseyeDashboard-cardTitleRight', this.mapMarkup ? this.mapControls() : null),
          ]),
          m('.BirdseyeDashboard-map', {
            oncreate: (v: Mithril.VnodeDOM) => this.mountMap(v.dom as HTMLElement),
            onupdate: (v: Mithril.VnodeDOM) => this.mountMap(v.dom as HTMLElement),
          }),
        ]
      ),
    ]);
  }

  /**
   * The by-month / by-year table: the whole rollup history folded into
   * calendar rows, so the numbers stop reading as one endlessly growing
   * total. The bucket still accumulating is marked so a low number reads
   * as "in progress", not as a collapse.
   */
  historyCard(): Mithril.Children {
    const rows = this.historyUnit === 'year' ? this.years : this.months;

    if (!rows.length) return null;

    const label = (r: PeriodRow) => (this.historyUnit === 'year' ? String(r.year) : monthName(String(r.month)));

    return m('.BirdseyeDashboard-card.BirdseyeDashboard-historyCard', [
      m('.BirdseyeDashboard-cardTitle', [
        m('span.BirdseyeDashboard-cardTitleText', trans('history')),
        this.years.length > 1
          ? m(
              '.BirdseyeDashboard-cardTitleRight',
              (['month', 'year'] as const).map((u) =>
                m(
                  'button.Button.Button--link',
                  {
                    type: 'button',
                    className: this.historyUnit === u ? 'is-active' : '',
                    onclick: () => {
                      this.historyUnit = u;
                    },
                  },
                  trans(u === 'month' ? 'by_month' : 'by_year')
                )
              )
            )
          : null,
      ]),
      m('.BirdseyeDashboard-historyScroll', [
        m('table.BirdseyeDashboard-historyTable', [
          m('thead', [
            m('tr', [
              m('th', ''),
              m('th', trans('visitors')),
              m('th', trans('pageviews')),
              m('th', trans('bounce_rate')),
              m('th', trans('avg_visit')),
              m('th', trans('posts')),
              m('th', trans('signups')),
            ]),
          ]),
          m(
            'tbody',
            rows.map((r) =>
              m('tr', { key: label(r) }, [
                m('td.BirdseyeDashboard-historyLabel', [
                  label(r),
                  r.partial ? m('span.BirdseyeDashboard-historyPartial', transText('so_far')) : null,
                ]),
                m('td', String(r.visits)),
                m('td', String(r.pageviews)),
                m('td', r.bounce_rate === null ? '\u2014' : `${Math.round(r.bounce_rate * 100)}%`),
                m('td', r.avg_session_sec === null ? '\u2014' : fmtDur(r.avg_session_sec)),
                m('td', String(r.posts)),
                m('td', String(r.registrations)),
              ])
            )
          ),
        ]),
      ]),
    ]);
  }

  /** The map card's own controls: expand/close, plus zoom buttons once
   *  expanded. The wheel and pinch gestures cover the same ground, but a
   *  button is the only route for a keyboard user or a plain mouse. */
  mapControls(): Mithril.Children {
    const btn = (icon: string, key: string, onclick: () => void) =>
      m('button.BirdseyeDashboard-export', { type: 'button', title: transText(key), 'aria-label': transText(key), onclick }, m('i.fas.' + icon));

    return [
      this.mapExpanded
        ? [
            btn('fa-search-minus', 'zoom_out', () => this.zoomMap(1 / MAP_ZOOM_STEP)),
            btn('fa-search-plus', 'zoom_in', () => this.zoomMap(MAP_ZOOM_STEP)),
          ]
        : null,
      this.mapExpanded ? btn('fa-compress', 'map_collapse', () => this.collapseMap()) : btn('fa-expand', 'map_expand', () => this.expandMap()),
    ];
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
   *  header toggle. Same data, same links — only the shape changes. `key`
   *  names the per-card CSV export; `unit` is what the numbers count. */
  card(key: string, title: Mithril.Children, rows: ListRow[], labeller: (l: string) => Mithril.Children, unit: Unit, note?: Mithril.Children) {
    return this.viewMode === 'pie' ? this.donut(key, title, rows, labeller, unit, note) : this.list(key, title, rows, labeller, unit, note);
  }

  /** A card title with its optional note and, when there is something to
   *  export, a small CSV download control on the right. */
  cardTitleEl(title: Mithril.Children, note: Mithril.Children | null, onExport: (() => void) | null) {
    return m('.BirdseyeDashboard-cardTitle', [
      m('span.BirdseyeDashboard-cardTitleText', title),
      m('.BirdseyeDashboard-cardTitleRight', [
        note ? m('span.BirdseyeDashboard-span', note) : null,
        onExport
          ? m(
              'button.BirdseyeDashboard-export',
              { type: 'button', title: transText('export'), 'aria-label': transText('export'), onclick: onExport },
              m('i.fas.fa-download')
            )
          : null,
      ]),
    ]);
  }

  /** Download one breakdown card as a two-column CSV, using the same
   *  human-readable labels the card shows; the value column is named for
   *  what the card actually counts.
   *
   *  The new-members card is the exception: it displays "Jul 27" but exports
   *  the raw ISO date, which a spreadsheet can actually sort. */
  exportRows(key: string, rows: ListRow[], labeller: (l: string) => Mithril.Children, unit: Unit) {
    const csvLabel = key === 'new_members' ? (l: string) => l : labeller;
    const data: unknown[][] = [[transText('col_label'), transText(UNIT_COL[unit])]];
    rows.forEach((r) => data.push([String(csvLabel(r.label)), r.visits]));
    downloadCsv(`birdseye-${key}-${this.range}.csv`, toCsv(data));
  }

  /** Download the whole current range as one CSV: totals, the daily series,
   *  and every breakdown, each under a labelled section. */
  exportAll() {
    const block = this.ranges?.[this.range];
    if (!block) return;

    const t = block.totals;

    // One uniform table so it opens cleanly as a spreadsheet: every value is
    // a (section, label, value) row, filterable/pivotable by the Section
    // column, rather than stacked blocks with mismatched headers.
    const rows: unknown[][] = [[transText('col_section'), transText('col_label'), transText('col_value')]];
    const push = (section: Mithril.Children, label: Mithril.Children, value: unknown) => rows.push([String(section), String(label), value]);

    const totals = transText('totals');
    push(totals, transText('visitors'), t.visits);
    push(totals, transText('pageviews'), t.pageviews);
    push(totals, transText('bounce_rate'), t.bounce_rate === null ? '' : Math.round(t.bounce_rate * 100) / 100);
    push(totals, transText('avg_visit'), t.avg_session_sec === null ? '' : Math.round(t.avg_session_sec));
    push(totals, transText('posts'), t.posts);
    push(totals, transText('signups'), t.registrations);

    const perDay = transText('visitors_per_day');
    block.timeseries.forEach((p) => push(perDay, p.date, p.visits));

    const breakdowns: [string, ListRow[], (l: string) => Mithril.Children][] = [
      [transText('top_pages'), block.pages, (l) => l || '/'],
      [transText('top_discussions'), block.discussions, (l) => l],
      [transText('tags'), block.tags || [], (l) => l],
      [transText('sources'), block.sources, (l) => l || transText('direct')],
      [transText('searches'), block.searches, (l) => l],
      [transText('devices'), block.devices, (l) => l || transText('unknown')],
      [transText('countries'), block.locations, countryName],
      [transText('unanswered'), this.unanswered, (l) => l],
      // ISO dates, not shortDate() — a spreadsheet can sort these.
      [transText('new_members'), block.new_members, (l) => l],
    ];

    breakdowns.forEach(([name, list, labeller]) => list.forEach((r) => push(name, String(labeller(r.label)), r.visits)));

    downloadCsv(`birdseye-report-${this.range}.csv`, toCsv(rows));
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

  list(key: string, title: Mithril.Children, rows: ListRow[], labeller: (l: string) => Mithril.Children, unit: Unit, note?: Mithril.Children) {
    const max = Math.max(...rows.map((r) => r.visits), 1);

    return m('.BirdseyeDashboard-card', [
      this.cardTitleEl(title, note ?? null, rows.length ? () => this.exportRows(key, rows, labeller, unit) : null),
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
  donut(key: string, title: Mithril.Children, rows: ListRow[], labeller: (l: string) => Mithril.Children, unit: Unit, note?: Mithril.Children) {
    const present = rows.filter((r) => r.visits > 0);
    const cardTitle = this.cardTitleEl(title, note ?? null, present.length ? () => this.exportRows(key, rows, labeller, unit) : null);
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
            m('.BirdseyeDashboard-donutTotalLabel', transText(`${unit}_word`)),
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
        stat(trans('signups'), d.registrations),
      ]),
    ]);
  }

  /**
   * Insert the SVG once, then (re)shade on every render — mirrors the
   * original site dashboard: accent alpha scaled to the range max, hover
   * tooltip with count and share.
   */
  mountMap(el: HTMLElement) {
    if (!this.mapMarkup) return;

    this.mapEl = el;

    if (!el.querySelector('svg')) {
      el.innerHTML = this.mapMarkup;
      el.style.position = 'relative';

      const tip = document.createElement('div');
      tip.className = 'BirdseyeDashboard-mapTip';
      el.appendChild(tip);
      this.mapTip = tip;

      this.wireMapGestures(el);

      const svg = el.querySelector('svg')!;
      svg.querySelectorAll('path').forEach((p) => {
        const t = p.querySelector('title');
        if (t) {
          (p as SVGPathElement).dataset.name = t.textContent ?? '';
          t.remove();
        }
      });

      svg.addEventListener('pointermove', (e) => {
        // Only a cursor tracks the map. A finger has no hover to follow, and
        // its pointer is destroyed the moment it lifts, so touch raises the
        // tooltip on the tap itself (see wireMapGestures).
        if (e.pointerType !== 'mouse') return;
        // A tooltip chasing the cursor mid-drag reads as a stuck label, and
        // it would name whichever country slid under the pointer anyway.
        if (this.mapPointers.size) this.hideMapTip();
        else this.showMapTip(e.clientX, e.clientY);
      });
      svg.addEventListener('pointerleave', (e) => {
        if (e.pointerType === 'mouse') this.hideMapTip();
      });
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

    // A redraw can arrive mid-gesture (the zoom buttons are Mithril
    // handlers); re-assert the current zoom so it survives one. The inline
    // card never zooms, so it is left with no transform at all.
    if (this.mapExpanded) this.applyMapTransform(el, svg as SVGSVGElement);
  }

  /**
   * Name the country at a point on screen, or hide the tooltip if that point
   * is not on one. Hit-tested rather than read off the event target: a
   * gesture holds a pointer capture on the container, so the country's own
   * path never sees the events that end it.
   */
  showMapTip(clientX: number, clientY: number, tapped = false) {
    const el = this.mapEl;
    const tip = this.mapTip;
    if (!el || !tip) return;

    const hit = document.elementFromPoint(clientX, clientY);
    const p = (hit ? hit.closest('path') : null) as SVGPathElement | null;
    if (!p || !el.contains(p)) return this.hideMapTip();

    // Light the country up for as long as it is named: a finger leaves no
    // hover behind, so without this the label points at nothing.
    if (this.mapTipPath !== p) {
      this.mapTipPath?.classList.remove('is-named');
      p.classList.add('is-named');
      this.mapTipPath = p;
    }

    const v = parseInt(p.dataset.v || '0', 10);
    tip.textContent = '';
    tip.appendChild(Object.assign(document.createElement('strong'), { textContent: p.dataset.name || '' }));
    tip.appendChild(document.createElement('br'));
    tip.appendChild(document.createTextNode(v ? `${v} ${transText('visitors_word')} · ${p.dataset.p}%` : transText('no_visitors')));
    tip.style.display = 'block';

    const r = el.getBoundingClientRect();
    const x = clientX - r.left;
    const y = clientY - r.top;
    const flip = x > r.width / 2;
    tip.style.left = flip ? '' : `${x + 14}px`;
    tip.style.right = flip ? `${r.width - x + 14}px` : '';
    // A fingertip covers what it just touched, so a tapped tooltip sits above
    // the tap; a cursor is small enough to have one hang below it.
    tip.style.top = tapped ? `${Math.max(0, y - tip.offsetHeight - 16)}px` : `${y + 12}px`;
  }

  hideMapTip() {
    if (this.mapTip) this.mapTip.style.display = 'none';
    this.mapTipPath?.classList.remove('is-named');
    this.mapTipPath = null;
  }

  /**
   * Wheel, drag, and pinch on the map — expanded only. On the inline card the
   * wheel must keep scrolling the page: a map that swallowed the scroll
   * halfway down the dashboard would be a trap, not a feature.
   */
  wireMapGestures(el: HTMLElement) {
    const svg = () => el.querySelector('svg') as SVGSVGElement | null;

    el.addEventListener(
      'wheel',
      (e) => {
        const s = svg();
        if (!this.mapExpanded || !s) return;
        e.preventDefault();
        // deltaMode 1 counts lines and 2 counts pages; normalise to pixels so
        // one notch zooms by about the same amount in every browser.
        const px = e.deltaMode === 1 ? e.deltaY * 16 : e.deltaMode === 2 ? e.deltaY * 400 : e.deltaY;
        this.zoomMapAt(el, s, Math.pow(0.9985, px), e.clientX, e.clientY);
      },
      { passive: false }
    );

    // Double-click expands from the card (Tutrix asked to click the map
    // itself, d/39605/41) and steps the zoom once expanded, wrapping back to
    // fitted at the top so there is always a way out without the buttons.
    el.addEventListener('dblclick', (e) => {
      const s = svg();
      if (!this.mapExpanded) {
        this.expandMap();
        m.redraw();
      } else if (s) {
        if (this.mapScale >= MAP_MAX_ZOOM) this.resetMapZoom();
        else this.zoomMapAt(el, s, MAP_ZOOM_STEP, e.clientX, e.clientY);
      }
    });

    el.addEventListener('pointerdown', (e) => {
      // Tracked on the card as well as full screen: tap-to-read is the only
      // way to name a country without a cursor, wherever the map is drawn.
      this.mapTap = e.isPrimary ? { id: e.pointerId, x: e.clientX, y: e.clientY, moved: 0 } : null;
      this.hideMapTip();

      if (!this.mapExpanded) return;
      this.mapPointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
      // Capture so a drag that runs off the map (or off the window) keeps
      // reporting, and so its pointerup still arrives here.
      try {
        el.setPointerCapture(e.pointerId);
      } catch {
        // Capture is a nicety; the gesture still works without it.
      }
      this.mapDrag = this.mapPointers.size === 1 ? { x: e.clientX, y: e.clientY } : null;
      this.mapPinch = this.mapPointers.size >= 2 ? this.pinchState() : null;
    });

    el.addEventListener('pointermove', (e) => {
      const tap = this.mapTap;
      if (tap && tap.id === e.pointerId) {
        tap.moved += Math.hypot(e.clientX - tap.x, e.clientY - tap.y);
        tap.x = e.clientX;
        tap.y = e.clientY;
      }

      if (!this.mapPointers.has(e.pointerId)) return;
      this.mapPointers.set(e.pointerId, { x: e.clientX, y: e.clientY });

      const s = svg();
      if (!s) return;

      if (this.mapPointers.size >= 2) {
        const now = this.pinchState();
        const prev = this.mapPinch;
        if (now && prev && prev.dist > 0) {
          // Zoom on the point between the fingers, then let that point's own
          // drift pan the map, so a pinch can move and scale in one gesture.
          this.zoomMapAt(el, s, now.dist / prev.dist, prev.cx, prev.cy);
          this.panMap(el, s, now.cx - prev.cx, now.cy - prev.cy);
        }
        this.mapPinch = now;
      } else if (this.mapDrag) {
        this.panMap(el, s, e.clientX - this.mapDrag.x, e.clientY - this.mapDrag.y);
        this.mapDrag = { x: e.clientX, y: e.clientY };
      }
    });

    const endPointer = (e: PointerEvent) => {
      const tap = this.mapTap;
      this.mapTap = null;
      // A finger that lifts where it landed is asking to read that country.
      // Raised here rather than on the move, and left up afterwards, because
      // lifting also destroys the pointer: there is no later event to show it
      // in, and pointerleave arrives immediately behind pointerup.
      if (
        tap &&
        tap.id === e.pointerId &&
        e.type === 'pointerup' &&
        e.pointerType !== 'mouse' &&
        tap.moved <= MAP_TAP_SLOP &&
        this.mapPointers.size <= 1
      ) {
        this.showMapTip(e.clientX, e.clientY, true);
      }

      if (!this.mapPointers.delete(e.pointerId)) return;
      try {
        el.releasePointerCapture(e.pointerId);
      } catch {
        // Already released with the pointer itself.
      }
      // Lifting one finger of a pinch leaves the other mid-drag: restart the
      // drag from where that finger is now, or the map jumps.
      const rest = this.mapPointers.values().next().value;
      this.mapDrag = this.mapPointers.size === 1 && rest ? { x: rest.x, y: rest.y } : null;
      this.mapPinch = this.mapPointers.size >= 2 ? this.pinchState() : null;
    };

    el.addEventListener('pointerup', endPointer);
    el.addEventListener('pointercancel', endPointer);
  }

  /** Distance between the first two pointers, and the point between them. */
  pinchState(): { dist: number; cx: number; cy: number } | null {
    const pts = Array.from(this.mapPointers.values());
    if (pts.length < 2) return null;
    const [a, b] = pts;
    return { dist: Math.hypot(b.x - a.x, b.y - a.y), cx: (a.x + b.x) / 2, cy: (a.y + b.y) / 2 };
  }

  /**
   * Take over the screen. Real browser fullscreen goes on top where the API
   * exists, so the address bar and tabs go too — but the fixed overlay does
   * the actual work and never depends on that call landing, because iPhone
   * Safari still has no fullscreen for anything but a <video>.
   */
  expandMap() {
    if (this.mapExpanded) return;

    this.mapExpanded = true;
    this.resetMapZoom();

    document.body.classList.add('birdseye-mapExpanded');
    document.addEventListener('keydown', this.onMapKeydown, true);
    document.addEventListener('fullscreenchange', this.onFullscreenChange);
    window.addEventListener('resize', this.onMapResize);

    const card = this.mapEl?.closest('.BirdseyeDashboard-mapCard') as (HTMLElement & { webkitRequestFullscreen?: () => void }) | null;

    try {
      const req = card?.requestFullscreen?.() ?? card?.webkitRequestFullscreen?.();
      // A rejected request (an iframe without the permission, a browser that
      // wants a different gesture) is fine — the overlay is already up.
      if (req && typeof (req as Promise<void>).catch === 'function') (req as Promise<void>).catch(() => {});
    } catch {
      // Same again for the browsers that throw instead of rejecting.
    }
  }

  collapseMap() {
    if (!this.mapExpanded) return;

    this.mapExpanded = false;
    this.mapPointers.clear();
    this.mapDrag = null;
    this.mapPinch = null;
    this.mapTap = null;
    this.hideMapTip();
    this.resetMapZoom();

    document.body.classList.remove('birdseye-mapExpanded');
    document.removeEventListener('keydown', this.onMapKeydown, true);
    document.removeEventListener('keyup', this.onMapKeyup, true);
    document.removeEventListener('fullscreenchange', this.onFullscreenChange);
    window.removeEventListener('resize', this.onMapResize);

    try {
      const doc = document as Document & { webkitFullscreenElement?: Element; webkitExitFullscreen?: () => void };
      if (doc.fullscreenElement) document.exitFullscreen();
      else if (doc.webkitFullscreenElement) doc.webkitExitFullscreen?.();
    } catch {
      // Nothing to leave, or the browser refused — the overlay is down either
      // way, which is what the reader sees.
    }
  }

  /** Escape closes the map. Capture phase and stopPropagation on purpose: on
   *  the forum side the dashboard sits in a modal whose own Escape handler
   *  would otherwise close the whole thing out from under the map. */
  onMapKeydown = (e: KeyboardEvent) => {
    if (!this.mapExpanded || e.key !== 'Escape') return;
    e.preventDefault();
    e.stopPropagation();
    this.collapseMap();
    // After collapsing, because collapseMap() clears this listener too: core
    // closes its modal on the key coming back *up* (verified on 1.8, and on
    // 2.0 wherever the browser has no CloseWatcher), so the one press that
    // closed the map has to be swallowed at both ends.
    document.addEventListener('keyup', this.onMapKeyup, true);
    m.redraw();
  };

  onMapKeyup = (e: KeyboardEvent) => {
    document.removeEventListener('keyup', this.onMapKeyup, true);
    if (e.key !== 'Escape') return;
    e.preventDefault();
    e.stopPropagation();
  };

  /** Leaving native fullscreen by any other route — Escape handled by the
   *  browser, its own control, a gesture — has to drop the overlay too, or
   *  the map is left covering a page nobody asked it to cover. */
  onFullscreenChange = () => {
    const doc = document as Document & { webkitFullscreenElement?: Element };
    if (this.mapExpanded && !doc.fullscreenElement && !doc.webkitFullscreenElement) {
      this.collapseMap();
      m.redraw();
    }
  };

  /** A rotated phone or a resized window changes what "fitted" means, and a
   *  pan clamped to the old size would leave the map hanging off an edge. */
  onMapResize = () => {
    const svg = this.mapEl?.querySelector('svg') as SVGSVGElement | null;
    if (this.mapEl && svg) this.applyMapTransform(this.mapEl, svg);
  };

  onremove(vnode: Mithril.VnodeDOM) {
    // The modal can be closed (or the admin page navigated away from) while
    // the map is expanded; the body class and the document listeners must not
    // outlive the component that put them there.
    this.collapseMap();
    super.onremove?.(vnode);
  }

  resetMapZoom() {
    this.mapScale = 1;
    this.mapX = 0;
    this.mapY = 0;
    const svg = this.mapEl?.querySelector('svg') as SVGSVGElement | null;
    if (svg) svg.style.transform = '';
  }

  /** Zoom about the middle of the map — what the buttons use, since they have
   *  no cursor to zoom towards. */
  zoomMap(factor: number) {
    const el = this.mapEl;
    const svg = el?.querySelector('svg') as SVGSVGElement | null;
    if (!el || !svg) return;
    const r = el.getBoundingClientRect();
    this.zoomMapAt(el, svg, factor, r.left + r.width / 2, r.top + r.height / 2);
  }

  /** Zoom keeping whatever sits at (cx, cy) under that same point. */
  zoomMapAt(el: HTMLElement, svg: SVGSVGElement, factor: number, cx: number, cy: number) {
    // A tapped tooltip is pinned to a place on the screen, not to its
    // country, so it has to go the moment the map moves under it.
    this.hideMapTip();

    const from = this.mapScale;
    const to = Math.min(Math.max(from * factor, 1), MAP_MAX_ZOOM);
    if (to === from) return;

    const r = svg.getBoundingClientRect();
    const ratio = to / from;
    // The map's left edge sits at (r.left - mapX) before the translate, so
    // solving "anchor stays put" for the new offset gives this.
    this.mapX = cx - ratio * (cx - r.left) - (r.left - this.mapX);
    this.mapY = cy - ratio * (cy - r.top) - (r.top - this.mapY);
    this.mapScale = to;

    this.applyMapTransform(el, svg);
  }

  panMap(el: HTMLElement, svg: SVGSVGElement, dx: number, dy: number) {
    if (this.mapScale === 1) return;
    this.mapX += dx;
    this.mapY += dy;
    this.applyMapTransform(el, svg);
  }

  /**
   * Write the zoom out to the SVG, then clamp it so the map can never be
   * dragged off its own frame: an edge stays pinned once it is reached, and a
   * map smaller than the frame re-centres itself.
   */
  applyMapTransform(el: HTMLElement, svg: SVGSVGElement) {
    const write = () => (svg.style.transform = `translate(${this.mapX}px, ${this.mapY}px) scale(${this.mapScale})`);

    write();

    // Measure after writing: the rect has to reflect the transform we are
    // clamping, and the un-translated edge is then just rect minus offset.
    const frame = el.getBoundingClientRect();
    const r = svg.getBoundingClientRect();
    const x = clampAxis(this.mapX, r.left - this.mapX, r.width, frame.left, frame.width);
    const y = clampAxis(this.mapY, r.top - this.mapY, r.height, frame.top, frame.height);

    if (x !== this.mapX || y !== this.mapY) {
      this.mapX = x;
      this.mapY = y;
      write();
    }
  }
}

/** Clamp one axis of the map's offset: fill the frame while the map is bigger
 *  than it, centre it once it is not. */
function clampAxis(offset: number, edge: number, size: number, frameStart: number, frameSize: number): number {
  if (size <= frameSize) return frameStart + (frameSize - size) / 2 - edge;
  return Math.min(Math.max(offset, frameStart + frameSize - edge - size), frameStart - edge);
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

function transText(key: string, params: Record<string, unknown> = {}): string {
  return flattenTrans(app.translator.trans(`linkrobins-birdseye.lib.dashboard.${key}`, params));
}

/**
 * A parameterised translation comes back as an array of parts, not a string.
 * String() on that joins with commas ("2, of ,3, signups") — and we can't use
 * core's extractText, because 2.0's `trans(id, params, true)` overload doesn't
 * exist on 1.8 and this bundle deliberately imports nothing from flarum/*.
 */
function flattenTrans(v: unknown): string {
  if (Array.isArray(v)) return v.map(flattenTrans).join('');
  if (v && typeof v === 'object') return flattenTrans((v as { children?: unknown }).children);
  return v === undefined || v === null ? '' : String(v);
}

function shortDate(iso: string): string {
  try {
    return new Date(`${iso}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
  } catch {
    return iso;
  }
}

/** "2026-08" -> "Aug 2026" in the viewer's locale, falling back to the raw key. */
function monthName(ym: string): string {
  const [y, mo] = ym.split('-').map(Number);

  if (!y || !mo) return ym;

  try {
    return new Date(Date.UTC(y, mo - 1, 1)).toLocaleDateString(undefined, { month: 'short', year: 'numeric', timeZone: 'UTC' });
  } catch {
    return ym;
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

// --- CSV export ---
// Client-side only: the range is already loaded in the component, so the admin
// downloads a file the browser builds. Birdseye never transmits it anywhere;
// what the admin does with the export is their call.
function csvCell(v: unknown): string {
  let s = String(v ?? '');
  // Neutralise spreadsheet formula injection: a cell starting with = + - @, a
  // tab, or a carriage return is evaluated as a formula by Excel/Sheets, so a
  // visitor-controlled value (e.g. a captured search term "=WEBSERVICE(...)")
  // could run when the admin opens the export. A leading apostrophe keeps it text.
  if (/^[=+\-@\t\r]/.test(s)) s = "'" + s;
  return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
}

function toCsv(rows: unknown[][]): string {
  return rows.map((r) => r.map(csvCell).join(',')).join('\r\n');
}

function downloadCsv(filename: string, csv: string): void {
  // Prepend a UTF-8 BOM so spreadsheet apps read non-ASCII (e.g. search terms) correctly.
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 0);
}
