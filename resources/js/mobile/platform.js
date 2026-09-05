import { Capacitor } from '@capacitor/core';

/**
 * Story 12.1: platform detection for hybrid vs web.
 */
export function isNativePlatform() {
  try {
    return Capacitor.isNativePlatform();
  } catch {
    return false;
  }
}

export function getPlatform() {
  if (!isNativePlatform()) {
    return 'web';
  }

  const platform = Capacitor.getPlatform();
  if (platform === 'ios' || platform === 'android') {
    return platform;
  }

  return 'web-hybrid';
}

export function hybridClientLabel() {
  return isNativePlatform() ? 'hybrid' : 'web';
}
