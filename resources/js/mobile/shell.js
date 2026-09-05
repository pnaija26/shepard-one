import { App } from '@capacitor/app';
import { SplashScreen } from '@capacitor/splash-screen';
import { StatusBar, Style } from '@capacitor/status-bar';
import { isNativePlatform, getPlatform } from './platform';
import { initConnectivityMonitor } from './offlineQueue';
import { assertHttpsBaseUrl, apiBaseUrl } from './apiConfig';

/**
 * Story 12.1: church-branded native shell bootstrap.
 */
export async function initHybridShell() {
  assertHttpsBaseUrl(apiBaseUrl());
  await initConnectivityMonitor();

  if (!isNativePlatform()) {
    return { platform: 'web', native: false };
  }

  document.documentElement.dataset.hybridPlatform = getPlatform();
  document.body.classList.add('hybrid-native');

  try {
    await StatusBar.setStyle({ style: Style.Dark });
    await StatusBar.setBackgroundColor({ color: '#123b2a' });
  } catch {
    /* web or unsupported */
  }

  try {
    await SplashScreen.hide();
  } catch {
    /* ignore */
  }

  try {
    App.addListener('appUrlOpen', (event) => {
      // Deep links are handled by feature stories; keep URL free of tokens.
      if (event?.url && /[?&#](access_token|refresh_token|token)=/i.test(event.url)) {
        console.warn('[hybrid] Ignoring deep link that contained a credential parameter.');
      }
    });
  } catch {
    /* ignore */
  }

  return { platform: getPlatform(), native: true };
}
