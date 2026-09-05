import { Network } from '@capacitor/network';
import { isNativePlatform } from './platform';

/**
 * Story 12.1: offline-tolerant queue with explicit sync states.
 */

export const SyncStatus = {
  IDLE: 'idle',
  OFFLINE: 'offline',
  PENDING: 'pending',
  SYNCING: 'syncing',
  CONFLICT: 'conflict',
  FAILED: 'failed',
  COMPLETED: 'completed',
};

const TOLERANT = new Set([
  'attendance.draft_note',
  'feedback.draft',
  'profile.draft_change',
  'notifications.mark_read',
]);

const queue = [];
let online = typeof navigator === 'undefined' ? true : navigator.onLine;
let listeners = new Set();

function emit() {
  const snapshot = getSyncSnapshot();
  listeners.forEach((fn) => fn(snapshot));
}

export function isOfflineTolerant(action) {
  return TOLERANT.has(action);
}

export function getSyncSnapshot() {
  const pending = queue.filter((item) => item.status === SyncStatus.PENDING || item.status === SyncStatus.FAILED);
  return {
    online,
    status: !online
      ? SyncStatus.OFFLINE
      : pending.length
        ? SyncStatus.PENDING
        : SyncStatus.IDLE,
    pendingCount: pending.length,
    items: queue.map((item) => ({ ...item })),
  };
}

export function subscribeSync(listener) {
  listeners.add(listener);
  listener(getSyncSnapshot());
  return () => listeners.delete(listener);
}

/**
 * Queue or reject an action based on offline policy.
 * @returns {{ accepted: boolean, status: string, message: string, item?: object }}
 */
export function enqueueOfflineAction(action, payload = {}) {
  if (!isOfflineTolerant(action)) {
    return {
      accepted: false,
      status: SyncStatus.FAILED,
      message: 'This action requires a connection and cannot be queued offline.',
    };
  }

  const item = {
    id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
    action,
    payload,
    status: online ? SyncStatus.PENDING : SyncStatus.PENDING,
    preservedInput: payload,
    message: online
      ? 'Queued for synchronization.'
      : 'Saved on device. Will sync when connectivity returns.',
    updatedAt: new Date().toISOString(),
  };

  queue.push(item);
  emit();

  return {
    accepted: true,
    status: item.status,
    message: item.message,
    item,
  };
}

export function markItemStatus(id, status, message = '') {
  const item = queue.find((entry) => entry.id === id);
  if (!item) {
    return null;
  }
  item.status = status;
  item.message = message || item.message;
  item.updatedAt = new Date().toISOString();
  emit();
  return item;
}

export async function flushOfflineQueue(executor) {
  if (!online) {
    return getSyncSnapshot();
  }

  for (const item of queue.filter((entry) => entry.status === SyncStatus.PENDING || entry.status === SyncStatus.FAILED)) {
    item.status = SyncStatus.SYNCING;
    emit();
    try {
      await executor(item);
      item.status = SyncStatus.COMPLETED;
      item.message = 'Synchronized.';
    } catch (error) {
      const conflict = error?.code === 'conflict' || error?.response?.status === 409;
      item.status = conflict ? SyncStatus.CONFLICT : SyncStatus.FAILED;
      item.message = conflict
        ? 'A newer version exists. Resolve the conflict before retrying.'
        : (error?.message || 'Synchronization failed. You can retry.');
    }
    item.updatedAt = new Date().toISOString();
    emit();
  }

  return getSyncSnapshot();
}

export async function initConnectivityMonitor() {
  if (typeof window !== 'undefined') {
    window.addEventListener('online', () => {
      online = true;
      emit();
    });
    window.addEventListener('offline', () => {
      online = false;
      emit();
    });
  }

  if (isNativePlatform()) {
    try {
      const status = await Network.getStatus();
      online = status.connected;
      Network.addListener('networkStatusChange', (status) => {
        online = status.connected;
        emit();
      });
    } catch {
      /* plugin unavailable in tests */
    }
  }

  emit();
  return getSyncSnapshot();
}
