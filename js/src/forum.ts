import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Button from 'flarum/common/components/Button';
import type ItemList from 'flarum/common/utils/ItemList';
import m from 'mithril';
import type Mithril from 'mithril';
import BirdseyeModal from './forum/components/BirdseyeModal';

app.initializers.add('linkrobins-birdseye', () => {
  // String-path extend keeps SessionDropdown's chunk lazy. Priority 45 lands
  // the entry right below core's Settings (50), above Administration (0).
  extend('flarum/forum/components/SessionDropdown', 'items', function (items: ItemList<Mithril.Children>) {
    if (!app.forum.attribute<boolean>('birdseyeCanViewStats')) return;

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
