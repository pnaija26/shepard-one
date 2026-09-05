import { Preferences } from '@capacitor/preferences';
import { isNativePlatform } from './platform';

const ACCESS_KEY = 'shepardone.access_token';
const REFRESH_KEY = 'shepardone.refresh_token';
const DEVICE_KEY = 'shepardone.device_id';

/**
 * Story 12.1: credential store contract.
 * Native → Capacitor Preferences (app-private). Web SPA → sessionStorage for
 * hybrid simulation and localStorage only for legacy web sessions migrated once.
 * Tokens are never written to URLs, logs, or analytics payloads.
 */
const memory = {
  access_token: null,
  refresh_token: null,
  device_id: null,
};

async function nativeSet(key, value) {
  if (value == null || value === '') {
    await Preferences.remove({ key });
    return;
  }
  await Preferences.set({ key, value: String(value) });
}

async function nativeGet(key) {
  const { value } = await Preferences.get({ key });
  return value;
}

function webRead(key) {
  try {
    return sessionStorage.getItem(key) ?? localStorage.getItem(key);
  } catch {
    return null;
  }
}

function webWrite(key, value) {
  try {
    if (value == null || value === '') {
      sessionStorage.removeItem(key);
      localStorage.removeItem(key);
      return;
    }
    // Hybrid-aware web: prefer sessionStorage (not durable "ordinary" storage).
    sessionStorage.setItem(key, value);
    if (key === ACCESS_KEY) {
      // Keep localStorage in sync for existing web interceptors during transition.
      localStorage.setItem(key, value);
    }
  } catch {
    /* private mode */
  }
}

export const credentialStore = {
  async hydrate() {
    if (isNativePlatform()) {
      memory.access_token = await nativeGet(ACCESS_KEY);
      memory.refresh_token = await nativeGet(REFRESH_KEY);
      memory.device_id = await nativeGet(DEVICE_KEY);
    } else {
      memory.access_token = webRead(ACCESS_KEY);
      memory.refresh_token = webRead(REFRESH_KEY);
      memory.device_id = webRead(DEVICE_KEY);
    }
    return memory;
  },

  getAccessToken() {
    return memory.access_token;
  },

  getRefreshToken() {
    return memory.refresh_token;
  },

  getDeviceId() {
    return memory.device_id;
  },

  async ensureDeviceId() {
    if (memory.device_id) {
      return memory.device_id;
    }
    const id = typeof crypto !== 'undefined' && crypto.randomUUID
      ? crypto.randomUUID().replace(/-/g, '').slice(0, 32)
      : `d${Date.now().toString(36)}${Math.random().toString(36).slice(2, 10)}`;
    await this.setDeviceId(id);
    return id;
  },

  async setDeviceId(deviceId) {
    memory.device_id = deviceId;
    if (isNativePlatform()) {
      await nativeSet(DEVICE_KEY, deviceId);
    } else {
      webWrite(DEVICE_KEY, deviceId);
    }
  },

  async setTokens({ accessToken, refreshToken = null }) {
    memory.access_token = accessToken ?? null;
    if (refreshToken !== undefined) {
      memory.refresh_token = refreshToken;
    }

    if (isNativePlatform()) {
      await nativeSet(ACCESS_KEY, memory.access_token);
      await nativeSet(REFRESH_KEY, memory.refresh_token);
    } else {
      webWrite(ACCESS_KEY, memory.access_token);
      webWrite(REFRESH_KEY, memory.refresh_token);
    }
  },

  async clear() {
    memory.access_token = null;
    memory.refresh_token = null;
    if (isNativePlatform()) {
      await nativeSet(ACCESS_KEY, null);
      await nativeSet(REFRESH_KEY, null);
    } else {
      webWrite(ACCESS_KEY, null);
      webWrite(REFRESH_KEY, null);
      try {
        localStorage.removeItem('access_token');
      } catch {
        /* ignore */
      }
    }
  },
};
