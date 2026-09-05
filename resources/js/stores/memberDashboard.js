import { defineStore } from 'pinia';
import { fetchDashboard } from '../api/memberDashboard';
import { extractApiError } from '../api/client';
import { useHybridStore } from './hybrid';
import { useAuthStore } from './auth';

export const useMemberDashboardStore = defineStore('memberDashboard', {
  state: () => ({
    dashboard: null,
    loading: false,
    error: null,
    offline: false,
    sessionExpired: false,
    lastLoadedAt: null,
  }),

  getters: {
    sections(state) {
      return state.dashboard?.sections ?? [];
    },
    quickActions(state) {
      return state.dashboard?.quick_actions ?? [];
    },
    visibleSections(state) {
      return (state.dashboard?.sections ?? []).filter((section) => section.state !== 'unauthorized');
    },
  },

  actions: {
    async load(options = {}) {
      const hybrid = useHybridStore();
      hybrid.watchSync();

      if (!hybrid.sync.online && !options.allowCached) {
        this.offline = true;
        this.error = 'You are offline. Reconnect to refresh your dashboard.';
        return this.dashboard;
      }

      this.loading = true;
      this.error = null;
      this.offline = false;
      this.sessionExpired = false;

      try {
        const { data } = await fetchDashboard({
          device: hybrid.isNative ? 'mobile' : 'web',
        });
        this.dashboard = data.data;
        this.lastLoadedAt = new Date().toISOString();
        return this.dashboard;
      } catch (error) {
        if (error?.response?.status === 401) {
          this.sessionExpired = true;
          this.error = 'Your session has expired. Sign in again to continue.';
          this.clearSensitiveCache();
        } else if (!navigator.onLine) {
          this.offline = true;
          this.error = 'You are offline. Your last dashboard view is preserved where safe.';
        } else {
          this.error = extractApiError(error, 'Unable to load your dashboard.');
        }
        throw error;
      } finally {
        this.loading = false;
      }
    },

    clearSensitiveCache() {
      const policy = this.dashboard?.session_policy;
      if (!policy?.clear_cache_on_logout) {
        return;
      }
      const sensitive = new Set(policy.sensitive_sections ?? []);
      if (!this.dashboard?.sections) {
        this.dashboard = null;
        return;
      }
      this.dashboard = {
        ...this.dashboard,
        sections: this.dashboard.sections.map((section) => (
          sensitive.has(section.key)
            ? { ...section, summary: {}, highlights: [], state: 'unavailable', available: false }
            : section
        )),
      };
    },

    async recover(action) {
      if (action === 'refresh') {
        return this.load({ allowCached: false });
      }
      if (action === 'sign_in') {
        const auth = useAuthStore();
        await auth.logout();
        window.location.href = '/login';
      }
      if (action === 'go_online') {
        return this.load();
      }
    },
  },
});
