import app from 'flarum/admin/app';
import { override } from 'flarum/common/extend';
import BirdseyeDashboard from './common/components/BirdseyeDashboard';

// Mithril is the global Flarum exposes, and core's own JSX compiles to these
// same `m(...)` calls. Deliberately not imported: flarum-webpack-config does
// not externalize mithril, so an import would bundle a second copy of it.
const m = (window as any).m;

app.initializers.add('linkrobins-birdseye', () => {
  // Settings + the viewStats permission.
  app.registry
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

  // Render the dashboard BELOW the whole settings form (under the Save / Reset
  // buttons), not as a setting inside it. Appending to ExtensionPage's own
  // content keeps the buttons directly under the last setting and drops the
  // dashboard beneath them. Guarded to Birdseye's own page.
  //
  // Overridden BY STRING PATH so ExtensionPage's chunk stays lazy: a runtime
  // import of the component would force it to load eagerly.
  override('flarum/admin/components/ExtensionPage', 'content', function (this: any, original: (v: any) => any, vnode: any) {
    const rendered = original(vnode);

    if (!this.extension || this.extension.id !== 'linkrobins-birdseye') return rendered;

    return [rendered, m('div', { className: 'container' }, m(BirdseyeDashboard))];
  });
});
