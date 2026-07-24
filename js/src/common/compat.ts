/**
 * Dual-major compatibility shims.
 *
 * Birdseye's frontend imports NOTHING from flarum/* so a single bundle runs on
 * both Flarum 1.8 (everything eager under flarum.core.compat) and Flarum 2.0
 * (lazy-chunk registry under flarum.reg). Every core value is resolved through
 * these feature-detecting helpers instead of an `import`, and mithril comes in
 * as the global `m` (webpack maps the one `mithril` import to window.m).
 */

/* eslint-disable @typescript-eslint/no-explicit-any */
declare const window: any;

const unwrap = (mod: any): any => (mod && mod.default ? mod.default : mod);

/**
 * Synchronously resolve a core module by its source path (e.g.
 * 'common/components/Modal'), or undefined if it isn't loaded. Reliable for the
 * eagerly-available base components Birdseye needs (Component, Modal, Button,
 * LoadingIndicator) on both majors.
 */
export function coreModule(path: string): any {
  const flarum = window.flarum;

  try {
    if (flarum && flarum.reg && typeof flarum.reg.get === 'function') {
      const mod = flarum.reg.get('core', path);
      if (mod) return unwrap(mod);
    }
  } catch (e) {
    /* fall through to the 1.x compat map */
  }

  try {
    const compat = flarum && flarum.core && flarum.core.compat;
    if (compat && compat[path]) return unwrap(compat[path]);
  } catch (e) {
    /* nothing to resolve */
  }

  return undefined;
}

/**
 * Run a callback once a core module is available (immediately if already
 * loaded). 2.0 waits on the registry's onLoad; 1.8 reads the eager compat map.
 * Used for patching page components (ExtensionPage, SessionDropdown) whose
 * chunks may load after this initializer on 2.0.
 */
export function onCoreModule(path: string, cb: (mod: any) => void): void {
  const flarum = window.flarum;

  try {
    if (flarum && flarum.reg && typeof flarum.reg.onLoad === 'function') {
      flarum.reg.onLoad('core', path, (mod: any) => cb(unwrap(mod)));
      return;
    }
  } catch (e) {
    /* fall through */
  }

  try {
    const compat = flarum && flarum.core && flarum.core.compat;
    if (compat && compat[path]) cb(unwrap(compat[path]));
  } catch (e) {
    /* nothing to patch */
  }
}

/**
 * extend()-style method wrap: run cb after the original, keeping the original's
 * return value. A local reimplementation so we don't import flarum/common/extend
 * (whose resolution differs across majors).
 */
export function extendMethod(proto: any, method: string, cb: (this: any, ret: any, ...args: any[]) => void): void {
  const original = proto[method];

  proto[method] = function (...args: any[]) {
    const value = original ? original.apply(this, args) : undefined;
    cb.call(this, value, ...args);
    return value;
  };
}

/**
 * override()-style method wrap: cb receives the bound original as its first
 * argument and returns the replacement value.
 */
export function overrideMethod(proto: any, method: string, cb: (this: any, original: (...a: any[]) => any, ...args: any[]) => any): void {
  const original = proto[method] || function () {};

  proto[method] = function (...args: any[]) {
    return cb.call(this, original.bind(this), ...args);
  };
}

/**
 * The admin extension-data registry: app.registry on 2.0, app.extensionData on
 * 1.8. Both expose the same for(id).registerSetting()/registerPermission() API.
 */
export function registry(app: any): any {
  return app.registry || app.extensionData;
}
