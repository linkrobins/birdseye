import app from 'flarum/admin/app';
import m from 'mithril';
import { override } from 'flarum/common/extend';
import BirdseyeDashboard from './common/components/BirdseyeDashboard';

// The core module registry (global). Used to patch ExtensionPage only once it
// has actually loaded — importing it at top level would be undefined at boot
// if the admin bundle code-splits it.
declare const flarum: any;

app.initializers.add('linkrobins-birdseye', () => {
  app.registry
    .for('linkrobins-birdseye')
    .registerSetting({
      setting: 'linkrobins-birdseye.collect',
      type: 'switch',
      label: app.translator.trans('linkrobins-birdseye.admin.settings.collect_label'),
      help: app.translator.trans('linkrobins-birdseye.admin.settings.collect_help'),
    })
    .registerSetting({
      setting: 'linkrobins-birdseye.license_key',
      type: 'text',
      label: app.translator.trans('linkrobins-birdseye.admin.settings.license_key_label'),
      help: app.translator.trans('linkrobins-birdseye.admin.settings.license_key_help'),
    })
    .registerSetting({
      setting: 'linkrobins-birdseye.geo_ip_prefix',
      type: 'switch',
      label: app.translator.trans('linkrobins-birdseye.admin.settings.geo_ip_prefix_label'),
      help: app.translator.trans('linkrobins-birdseye.admin.settings.geo_ip_prefix_help'),
    })
    .registerSetting({
      setting: 'linkrobins-birdseye.weekly_digest',
      type: 'switch',
      label: app.translator.trans('linkrobins-birdseye.admin.settings.weekly_digest_label'),
      help: app.translator.trans('linkrobins-birdseye.admin.settings.weekly_digest_help'),
    })
    // Lets non-admin groups open the dashboard from the forum's session
    // menu. Backend-enforced in StatsHandler/WorldMapHandler; nobody holds
    // it until the operator grants it.
    .registerPermission(
      {
        icon: 'fas fa-chart-line',
        label: app.translator.trans('linkrobins-birdseye.admin.permissions.view_stats_label'),
        permission: 'linkrobins-birdseye.viewStats',
      },
      'view'
    );

  // Render the dashboard BELOW the whole settings form (i.e. under the Save /
  // Reset buttons), not as a setting inside it — a registered setting renders
  // above the form's submit controls, which pushed the buttons beneath the
  // dashboard. Appending to the ExtensionPage's own content keeps the buttons
  // directly under the last setting and drops the dashboard beneath them.
  // Guarded to Birdseye's page, and applied through the registry so it is safe
  // whether ExtensionPage is eagerly or lazily loaded.
  flarum.reg.onLoad('core', 'admin/components/ExtensionPage', (mod: any) => {
    const ExtensionPage = mod.default || mod;

    override(ExtensionPage.prototype, 'content', function (this: any, original: (v: any) => any, vnode: any) {
      const rendered = original(vnode);

      if (this.extension?.id !== 'linkrobins-birdseye') return rendered;

      return [rendered, m('div', { className: 'container' }, m(BirdseyeDashboard))];
    });
  });
});
