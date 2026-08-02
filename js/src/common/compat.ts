/**
 * Core access for the Flarum 1.x line.
 *
 * Birdseye's frontend imports NOTHING from flarum/*: every core value is read
 * off the `flarum.core.compat` map that 1.x populates eagerly, and mithril
 * comes in as the global `m` (webpack maps the one `mithril` import to
 * window.m). That keeps the bundle free of build-time coupling to core's
 * module layout, which is what lets components be passed into factories rather
 * than extended at import time.
 */

/* eslint-disable @typescript-eslint/no-explicit-any */
declare const window: any;

const unwrap = (mod: any): any => (mod && mod.default ? mod.default : mod);

const compatMap = (): any => {
  try {
    const flarum = window.flarum;
    return (flarum && flarum.core && flarum.core.compat) || undefined;
  } catch (e) {
    return undefined;
  }
};

/**
 * Synchronously resolve a core module by its source path (e.g.
 * 'common/components/Modal'), or undefined if it isn't there. 1.x ships every
 * module eagerly, so this is reliable for everything Birdseye needs
 * (Component, Modal, Button, LoadingIndicator).
 */
export function coreModule(path: string): any {
  const compat = compatMap();

  return compat && compat[path] ? unwrap(compat[path]) : undefined;
}

/**
 * Run a callback with a core module. Nothing is lazy on 1.x, so this resolves
 * immediately. It stays a callback because the page components it patches
 * (ExtensionPage, SessionDropdown) are reached the same way on the 2.x line,
 * where their chunks may still be loading.
 */
export function onCoreModule(path: string, cb: (mod: any) => void): void {
  const mod = coreModule(path);

  if (mod) cb(mod);
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
 * The admin extension-data registry, which 1.x calls app.extensionData. It
 * exposes the for(id).registerSetting()/registerPermission() API.
 */
export function registry(app: any): any {
  return app.extensionData;
}
