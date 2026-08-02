import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import BirdseyeModal from './forum/components/BirdseyeModal';

// Mithril is the global Flarum exposes, and core's own JSX compiles to these
// same `m(...)` calls. Deliberately not imported: flarum-webpack-config does
// not externalize mithril, so an import would bundle a second copy of it.
const m = (window as any).m;

app.initializers.add('linkrobins-birdseye', () => {
  // An Analytics entry in the session menu, below core's Settings (priority 50)
  // and above Administration (0). Only offered when the forum payload says the
  // actor holds viewStats (the API enforces it regardless).
  //
  // Extended BY STRING PATH so SessionDropdown's chunk stays lazy: a runtime
  // import of the component would force it to load eagerly.
  extend('flarum/forum/components/SessionDropdown', 'items', function (items: any) {
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
