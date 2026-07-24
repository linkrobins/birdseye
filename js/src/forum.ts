import { coreModule, extendMethod, onCoreModule } from './common/compat';
import makeBirdseyeDashboard from './common/components/BirdseyeDashboard';
import makeBirdseyeModal from './forum/components/BirdseyeModal';

// Dual-major forum bundle: imports nothing from flarum/*, resolving core values
// from the runtime globals so one artifact runs on Flarum 1.8 and 2.0. See
// common/compat.ts.
declare const window: any;

window.app.initializers.add('linkrobins-birdseye', () => {
  const app = window.app;
  const m = window.m;

  const Button = coreModule('common/components/Button');
  const Modal = coreModule('common/components/Modal');

  // Build the shared dashboard + its modal host from the resolved base classes.
  const BirdseyeDashboard = makeBirdseyeDashboard(coreModule('common/Component'), coreModule('common/components/LoadingIndicator'));
  const BirdseyeModal = makeBirdseyeModal(Modal, BirdseyeDashboard);

  // An Analytics entry in the session menu, below core's Settings (priority 50)
  // and above Administration (0). Only offered when the forum payload says the
  // actor holds viewStats (the API enforces it regardless). Patched through the
  // core module registry so SessionDropdown's chunk stays lazy on 2.0.
  onCoreModule('forum/components/SessionDropdown', (SessionDropdown: any) => {
    extendMethod(SessionDropdown.prototype, 'items', function (this: any, items: any) {
      if (!items || !app.forum.attribute('birdseyeCanViewStats')) return;

      items.add(
        'birdseye',
        m(
          Button,
          {
            icon: 'fas fa-chart-line',
            onclick: () => app.modal.show(BirdseyeModal),
          },
          app.translator.trans('linkrobins-birdseye.forum.session_menu.analytics_button')
        ),
        45
      );
    });
  });
});
