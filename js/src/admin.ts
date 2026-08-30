import { coreModule, onCoreModule, overrideMethod, registry } from './common/compat';
import makeBirdseyeDashboard from './common/components/BirdseyeDashboard';

// Dual-major admin bundle: imports nothing from flarum/*, resolving core values
// from the runtime globals so one artifact runs on Flarum 1.8 and 2.0. See
// common/compat.ts.
declare const window: any;

window.app.initializers.add('linkrobins-birdseye', () => {
  const app = window.app;
  const m = window.m;

  // Settings + the viewStats permission. The registry is app.registry on 2.0
  // and app.extensionData on 1.8; both expose the same for(id) API.
  registry(app)
    .for('linkrobins-birdseye')
    .registerSetting({
      setting: 'linkrobins-birdseye.collect',
      type: 'switch',
      label: app.translator.trans('linkrobins-birdseye.admin.settings.collect_label'),
      help: app.translator.trans('linkrobins-birdseye.admin.settings.collect_help'),
    })
    .registerSetting({
      setting: 'linkrobins-birdseye.geo_ip_prefix',
      type: 'switch',
      label: app.translator.trans('linkrobins-birdseye.admin.settings.geo_ip_prefix_label'),
      help: app.translator.trans('linkrobins-birdseye.admin.settings.geo_ip_prefix_help'),
    })
    .registerSetting({
      setting: 'linkrobins-birdseye.geoip_db_path',
      type: 'text',
      placeholder: '/path/to/GeoLite2-Country.mmdb',
      label: app.translator.trans('linkrobins-birdseye.admin.settings.geoip_db_path_label'),
      help: app.translator.trans('linkrobins-birdseye.admin.settings.geoip_db_path_help'),
    })
    .registerSetting({
      setting: 'linkrobins-birdseye.country_header',
      type: 'text',
      placeholder: 'CF-IPCountry',
      label: app.translator.trans('linkrobins-birdseye.admin.settings.country_header_label'),
      help: app.translator.trans('linkrobins-birdseye.admin.settings.country_header_help'),
    })
    .registerSetting({
      setting: 'linkrobins-birdseye.weekly_digest',
      type: 'switch',
      label: app.translator.trans('linkrobins-birdseye.admin.settings.weekly_digest_label'),
      help: app.translator.trans('linkrobins-birdseye.admin.settings.weekly_digest_help'),
    })
    // Lets non-admin groups open the dashboard from the forum's session menu.
    // Backend-enforced in StatsHandler/WorldMapHandler; nobody holds it until
    // the operator grants it.
    .registerPermission(
      {
        icon: 'fas fa-chart-line',
        label: app.translator.trans('linkrobins-birdseye.admin.permissions.view_stats_label'),
        permission: 'linkrobins-birdseye.viewStats',
      },
      'view'
    );

  // The dashboard extends the core Component base class, so it's built from the
  // resolved base classes (both eagerly available on either major).
  const BirdseyeDashboard = makeBirdseyeDashboard(coreModule('common/Component'), coreModule('common/components/LoadingIndicator'));

  // Render the dashboard BELOW the whole settings form (under the Save / Reset
  // buttons), not as a setting inside it. Appending to ExtensionPage's own
  // content keeps the buttons directly under the last setting and drops the
  // dashboard beneath them. Guarded to Birdseye's page; patched through the core
  // module registry so it is safe whether ExtensionPage loads eagerly (1.8) or
  // lazily (2.0).
  onCoreModule('admin/components/ExtensionPage', (ExtensionPage: any) => {
    overrideMethod(ExtensionPage.prototype, 'content', function (this: any, original: (v: any) => any, vnode: any) {
      const rendered = original(vnode);

      if (!this.extension || this.extension.id !== 'linkrobins-birdseye') return rendered;

      return [rendered, m('div', { className: 'container' }, m(BirdseyeDashboard))];
    });
  });
});
