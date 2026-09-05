/**
 * Story 12.1: contextual permission requests with usable fallbacks.
 * Native prompts are only invoked after the caller has shown purpose UI.
 */

const catalog = {
  camera: {
    purpose: 'Scan membership or event QR codes for check-in.',
    fallback: 'Paste the QR payload manually.',
  },
  photos: {
    purpose: 'Attach a photo to a welfare or care follow-up.',
    fallback: 'Continue without an attachment.',
  },
  notifications: {
    purpose: 'Receive assignment, roster, and care alerts on this device.',
    fallback: 'Use the in-app inbox when online.',
  },
  microphone: {
    purpose: 'Record a short voice note when typing is impractical.',
    fallback: 'Enter notes as text instead.',
  },
};

const grants = new Map();

export function permissionMeta(key) {
  return catalog[key] ?? {
    purpose: 'This feature needs an additional device permission.',
    fallback: 'Continue without this capability.',
  };
}

/**
 * @param {string} key
 * @param {{ confirmedPurpose?: boolean }} options
 */
export async function requestPermission(key, options = {}) {
  const meta = permissionMeta(key);

  if (!options.confirmedPurpose) {
    return {
      status: 'blocked_before_purpose',
      granted: false,
      purpose: meta.purpose,
      fallback: meta.fallback,
      message: 'Explain why the permission is needed before requesting it.',
    };
  }

  // Foundation stub: record intent. Feature stories wire Capacitor Camera /
  // PushNotifications / etc. at the point of use.
  grants.set(key, 'granted');

  return {
    status: 'granted',
    granted: true,
    purpose: meta.purpose,
    fallback: meta.fallback,
  };
}

export function permissionFallback(key) {
  return permissionMeta(key).fallback;
}

export function listPermissionCatalog() {
  return Object.entries(catalog).map(([key, meta]) => ({ key, ...meta }));
}
