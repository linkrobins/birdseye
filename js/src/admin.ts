import app from 'flarum/admin/app';
import m from 'mithril';
import BirdseyeDashboard from './components/BirdseyeDashboard';

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
    .registerSetting(() => m(BirdseyeDashboard), -10);
});
