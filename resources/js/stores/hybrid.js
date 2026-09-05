import { defineStore } from 'pinia';
import api, { extractApiError } from '../api/client';
import {
  enqueueOfflineAction,
  getSyncSnapshot,
  isOfflineTolerant,
  subscribeSync,
  flushOfflineQueue,
} from '../mobile/offlineQueue';
import { listPermissionCatalog, requestPermission } from '../mobile/permissions';
import { getPlatform, isNativePlatform } from '../mobile/platform';

export const useHybridStore = defineStore('hybrid', {
  state: () => ({
    foundation: null,
    sync: getSyncSnapshot(),
    permissions: listPermissionCatalog(),
    lastPermissionResult: null,
    loading: false,
    error: null,
    unsubscribe: null,
  }),

  getters: {
    platform: () => getPlatform(),
    isNative: () => isNativePlatform(),
  },

  actions: {
    watchSync() {
      if (this.unsubscribe) {
        return;
      }
      this.unsubscribe = subscribeSync((snapshot) => {
        this.sync = snapshot;
      });
    },

    async loadFoundation() {
      this.loading = true;
      this.error = null;
      this.watchSync();
      try {
        const { data } = await api.get('/auth/hybrid/foundation');
        this.foundation = data.data;
        return this.foundation;
      } catch (error) {
        this.error = extractApiError(error, 'Unable to load hybrid foundation');
        throw error;
      } finally {
        this.loading = false;
      }
    },

    tryOfflineAction(action, payload = {}) {
      return enqueueOfflineAction(action, payload);
    },

    canQueue(action) {
      return isOfflineTolerant(action);
    },

    async requestDevicePermission(key, confirmedPurpose = false) {
      this.lastPermissionResult = await requestPermission(key, { confirmedPurpose });
      return this.lastPermissionResult;
    },

    async retrySync() {
      return flushOfflineQueue(async (item) => {
        // Foundation executor acknowledges drafts only; domain stories publish for real.
        if (!isOfflineTolerant(item.action)) {
          const err = new Error('Action is not offline-tolerant');
          err.code = 'unsupported';
          throw err;
        }
      });
    },
  },
});
