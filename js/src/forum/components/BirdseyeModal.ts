import type Mithril from 'mithril';
import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import BirdseyeDashboard from '../../common/components/BirdseyeDashboard';

// Mithril is the global Flarum exposes, and core's own JSX compiles to these
// same `m(...)` calls. Deliberately not imported: flarum-webpack-config does
// not externalize mithril, so an import would bundle a second copy of it.
const m = (window as any).m;

/**
 * The forum-side analytics view: the shared dashboard component inside a wide
 * modal. Opened from the session menu; only offered when the forum payload says
 * the actor holds the viewStats permission (the API enforces it regardless).
 */
export default class BirdseyeModal extends Modal {
  className(): string {
    return 'BirdseyeModal';
  }

  title(): Mithril.Children {
    return app.translator.trans('linkrobins-birdseye.lib.dashboard.title');
  }

  content(): Mithril.Children {
    return m('.Modal-body', m(BirdseyeDashboard, { hideTitle: true }));
  }
}
