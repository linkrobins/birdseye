/**
 * Core access for the Flarum 2.x line.
 *
 * Birdseye's frontend imports NOTHING from flarum/*: every core value is
 * resolved through the module registry at runtime, and mithril comes in as the
 * global `m` (webpack maps the one `mithril` import to window.m). That is what
 * lets components be passed into factories rather than extended at import
 * time, and it is also how the page components Birdseye patches are reached at
 * all: their chunks are lazy, so they may not exist when this initializer runs.
 */

/* eslint-disable @typescript-eslint/no-explicit-any */
declare const window: any;

const unwrap = (mod: any): any => (mod && mod.default ? mod.default : mod);

const registryOf = (): any => {
  try {
    const flarum = window.flarum;
    return (flarum && flarum.reg) || undefined;
  } catch (e) {
    return undefined;
  }
};

/**
 * Synchronously resolve a core module by its source path (e.g.
 * 'common/components/Modal'), or undefined if its chunk isn't loaded. Reliable
 * for the eagerly-available base components Birdseye needs (Component, Modal,
 * Button, LoadingIndicator); anything lazier wants onCoreModule below.
 */
export function coreModule(path: string): any {
  const reg = registryOf();

  try {
    if (reg && typeof reg.get === 'function') {
      const mod = reg.get('core', path);
      if (mod) return unwrap(mod);
    }
  } catch (e) {
    /* nothing to resolve */
  }

  return undefined;
}

/**
 * Run a callback once a core module is available, immediately if its chunk is
 * already in. This is how the page components (ExtensionPage, SessionDropdown)
 * are patched, since their chunks can load well after this initializer.
 */
export function onCoreModule(path: string, cb: (mod: any) => void): void {
  const reg = registryOf();

  try {
    if (reg && typeof reg.onLoad === 'function') {
      reg.onLoad('core', path, (mod: any) => cb(unwrap(mod)));
    }
  } catch (e) {
    /* nothing to patch */
  }
}

/**
 * extend()-style method wrap: run cb after the original, keeping the original's
 * return value. A local reimplementation so we don't import
 * flarum/common/extend, which would couple the bundle to core's module layout.
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
 * The admin extension-data registry, which 2.x calls app.registry. It exposes
 * the for(id).registerSetting()/registerPermission() API.
 */
export function registry(app: any): any {
  return app.registry;
}
