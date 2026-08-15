import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import humanTime from 'flarum/common/utils/humanTime';
import type Mithril from 'mithril';

// Mithril is the global Flarum exposes, and core's own JSX compiles to these
// same `m(...)` calls. Deliberately not imported: flarum-webpack-config does
// not externalize mithril, so an import would bundle a second copy of it.
const m = (window as any).m;

interface StatusResponse {
  state: string;
  lastSeenAt: string | null;
  boundTo: string | null;
}

/**
 * Tells the admin whether their Birdseye key actually works.
 *
 * Same shape as the banner on Warble, Chirp and Forage: one Alert at the top of
 * the settings page, plain language, the tick in the message rather than an
 * icon of its own, and only the Alert styles Flarum ships.
 *
 * The answer comes from Birdseye, not from anything this forum can see. The
 * sync only phones out once a complete day of events has built up, so a quiet
 * forum could go days without contact, and a cancelled subscription would
 * otherwise keep showing whatever was true last week.
 */
export default class BirdseyeStatus extends Component {
  status: StatusResponse | null = null;

  loaded = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);

    app
      .request<StatusResponse>({
        method: 'GET',
        url: app.forum.attribute('apiUrl') + '/birdseye/status',
      })
      .then((status) => {
        this.status = status;
        this.loaded = true;
        m.redraw();
      })
      .catch(() => {
        // A banner that cannot load is not worth an error dialog; the settings
        // below it still work.
        this.loaded = true;
        m.redraw();
      });
  }

  view() {
    // Nothing is drawn until there is something true to say. A banner that
    // guesses and then corrects itself is the problem this replaces.
    if (!this.loaded || !this.status) {
      return null;
    }

    const state = this.status.state;

    return m('div', { className: this.alertClass(state), style: 'margin-bottom:16px;' }, this.body(state));
  }

  alertClass(state: string): string {
    switch (state) {
      case 'active':
        return 'Alert Alert--success';
      case 'canceled':
      case 'invalid_key':
      case 'bound_elsewhere':
        return 'Alert Alert--error';
      // No key yet, a subscription still being set up, or Birdseye not
      // answering: none of those is anything the owner has done wrong.
      default:
        return 'Alert';
    }
  }

  body(state: string): Mithril.Children {
    if (state === 'active') {
      const seen = this.status?.lastSeenAt;

      // Until the first day of events is pushed there is nothing to report,
      // which is normal for a key pasted minutes ago and is not a fault.
      return seen ? this.trans('status_active_seen', { when: humanTime(seen) }) : this.trans('status_active_waiting');
    }

    if (state === 'bound_elsewhere') {
      return this.trans('status_bound_elsewhere', { forum: this.status?.boundTo || '' });
    }

    return this.trans('status_' + state);
  }

  trans(key: string, params: Record<string, unknown> = {}) {
    return app.translator.trans('linkrobins-birdseye.admin.' + key, params);
  }
}
