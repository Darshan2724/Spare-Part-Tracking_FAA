import axios from 'axios';

// Tier 1: Environment variable if injected during EAS build or development
// Tier 2: Default company LAN server fallback (never localhost)
const ENV_API_URL = process.env.EXPO_PUBLIC_API_URL || 'http://192.168.100.60:8080/api/v1';

let currentBaseUrl = ENV_API_URL;

const apiClient = axios.create({
  baseURL: currentBaseUrl,
  timeout: 15000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'Bypass-Tunnel-Reminder': 'true',
    'bypass-tunnel-reminder': 'true',
  },
});

/**
 * Dynamically configure or override the Server Base URL at runtime from the Mobile Login screen.
 * Handles inputs like "192.168.100.60:8080", "http://192.168.100.60:8080", or internal DNS names.
 */
export const setBaseUrl = (url) => {
  if (url && typeof url === 'string') {
    let cleanUrl = url.trim();
    if (!cleanUrl.startsWith('http://') && !cleanUrl.startsWith('https://')) {
      cleanUrl = `http://${cleanUrl}`;
    }
    if (!cleanUrl.endsWith('/api/v1')) {
      cleanUrl = cleanUrl.replace(/\/$/, '') + '/api/v1';
    }
    currentBaseUrl = cleanUrl;
    apiClient.defaults.baseURL = cleanUrl;
  }
};

export const getBaseUrl = () => {
  return currentBaseUrl;
};

export const setAuthToken = (token) => {
  if (token) {
    apiClient.defaults.headers.common['Authorization'] = `Bearer ${token}`;
  } else {
    delete apiClient.defaults.headers.common['Authorization'];
  }
};

export default apiClient;
