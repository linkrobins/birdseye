import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import m from 'mithril';
import type Mithril from 'mithril';

interface ListRow {
  label: string;
  visits: number;
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
}

/**
 * The analytics dashboard, rendered on the extension's admin page beneath
 * its settings. Reads local rollups via GET /api/birdseye/stats; the world
 * map SVG is fetched once and shaded per range.
 */
export default class BirdseyeDashboard extends Component {
  loading = true;
  ranges: Record<string, RangeBlock> | null = null;
  range = '30d';
  mapMarkup: string | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    const api = app.forum.attribute('apiUrl');

    app
      .request<{ ranges: Record<string, RangeBlock> }>({ method: 'GET', url: `${api}/birdseye/stats` })
      .then((data) => {
        this.ranges = data.ranges;
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
        m('h3', trans('title')),
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

      m('.BirdseyeDashboard-tiles', [
        this.tile(trans('visitors'), String(t.visits)),
        this.tile(trans('pageviews'), String(t.pageviews)),
        this.tile(trans('bounce_rate'), t.bounce_rate === null ? '—' : `${Math.round(t.bounce_rate * 100)}%`),
        this.tile(trans('avg_visit'), t.avg_session_sec === null ? '—' : fmtDur(t.avg_session_sec)),
        this.tile(trans('posts'), String(t.posts)),
        this.tile(trans('new_members'), String(t.registrations)),
      ]),

      this.chart(block),

      m('.BirdseyeDashboard-lists', [
        this.list(trans('top_pages'), block.pages, (l) => l || '/'),
        this.list(trans('top_discussions'), block.discussions, (l) => l),
        this.list(trans('sources'), block.sources, (l) => l || trans('direct')),
        this.list(trans('searches'), block.searches, (l) => l),
        this.list(trans('devices'), block.devices, (l) => (l ? l.charAt(0).toUpperCase() + l.slice(1) : trans('unknown'))),
        this.list(trans('countries'), block.locations.slice(0, 8), countryName),
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

  list(title: Mithril.Children, rows: ListRow[], labeller: (l: string) => string) {
    const max = Math.max(...rows.map((r) => r.visits), 1);

    return m('.BirdseyeDashboard-card', [
      m('.BirdseyeDashboard-cardTitle', title),
      rows.length
        ? rows.map((r) =>
            m('.BirdseyeDashboard-row', [
              m('.BirdseyeDashboard-rowBar', { style: { width: `${Math.round((r.visits / max) * 100)}%` } }),
              m('span.BirdseyeDashboard-rowLabel', labeller(r.label)),
              m('span.BirdseyeDashboard-rowValue', String(r.visits)),
            ])
          )
        : m('p.helpText', trans('no_data')),
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
        tip.appendChild(
          document.createTextNode(v ? `${v} ${transText('visitors_word')} · ${p.dataset.p}%` : transText('no_visitors'))
        );
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

function trans(key: string): Mithril.Children {
  return app.translator.trans(`linkrobins-birdseye.admin.dashboard.${key}`);
}

function transText(key: string): string {
  return String(app.translator.trans(`linkrobins-birdseye.admin.dashboard.${key}`));
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
