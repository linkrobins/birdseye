import m from 'mithril';
import type Mithril from 'mithril';

/**
 * The forum-side analytics view: the shared dashboard component inside a wide
 * modal. Opened from the session menu; only offered when the forum payload says
 * the actor holds the viewStats permission (the API enforces it regardless).
 *
 * Built through a factory so it never imports flarum/* directly: the core Modal
 * base class and the (already-built) dashboard component are passed in at
 * initializer time, letting one bundle run on Flarum 1.8 and 2.0. See
 * common/compat.ts.
 */
export default function makeBirdseyeModal(Modal: any, BirdseyeDashboard: any): any {
  const app = (window as any).app;

  return class BirdseyeModal extends Modal {
    className(): string {
      return 'BirdseyeModal';
    }

    title(): Mithril.Children {
      return app.translator.trans('linkrobins-birdseye.lib.dashboard.title');
    }

    content(): Mithril.Children {
      return m('.Modal-body', m(BirdseyeDashboard, { hideTitle: true }));
    }
  };
}
