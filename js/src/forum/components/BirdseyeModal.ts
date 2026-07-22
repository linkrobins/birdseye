import app from 'flarum/forum/app';
import Modal from 'flarum/common/components/Modal';
import m from 'mithril';
import type Mithril from 'mithril';
import BirdseyeDashboard from '../../common/components/BirdseyeDashboard';

/**
 * The forum-side analytics view: the shared dashboard component inside a
 * wide modal. Opened from the session menu; only offered when the forum
 * payload says the actor holds the viewStats permission (the API enforces
 * it regardless).
 */
export default class BirdseyeModal extends Modal {
  className() {
    return 'BirdseyeModal';
  }

  title(): Mithril.Children {
    return app.translator.trans('linkrobins-birdseye.lib.dashboard.title');
  }

  content(): Mithril.Children {
    return m('.Modal-body', m(BirdseyeDashboard, { hideTitle: true }));
  }
}
