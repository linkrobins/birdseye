import app from 'flarum/admin/app';
import m from 'mithril';
import BirdseyeDashboard from './common/components/BirdseyeDashboard';

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
    // The dashboard renders on the same page, beneath the settings form.
    .registerSetting(() => m(BirdseyeDashboard), -10)
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
});
