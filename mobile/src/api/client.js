import axios from 'axios';

// Canonical Server IP addresses:
// Primary current Wi-Fi server: 192.168.100.30:8080
// Company static server fallback: 192.168.9.200:8080
export const SERVER_PRESETS = [
  { id: 'wifi_100_30', label: '192.168.100.30:8080 (Current Wi-Fi)', host: '192.168.100.30:8080' },
  { id: 'server_9_200', label: '192.168.9.200:8080 (Company Server)', host: '192.168.9.200:8080' },
];

export const DEFAULT_HOST = 'http://192.168.100.30:8080/api/v1';
const ENV_API_URL = process.env.EXPO_PUBLIC_API_URL || DEFAULT_HOST;

let currentBaseUrl = ENV_API_URL;

const apiClient = axios.create({
  baseURL: currentBaseUrl,
  timeout: 15000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Dynamic request interceptor to always respect user-typed custom IP changes immediately
apiClient.interceptors.request.use((config) => {
  if (currentBaseUrl) {
    config.baseURL = currentBaseUrl;
  }
  return config;
});

/**
 * Robust Server Base URL normalizer.
 * Supports:
 * - "100.30" -> "http://192.168.100.30:8080/api/v1"
 * - "100.30:8080" -> "http://192.168.100.30:8080/api/v1"
 * - "192.168.100.30" -> "http://192.168.100.30:8080/api/v1"
 * - "192.168.100.30:8080" -> "http://192.168.100.30:8080/api/v1"
 * - "9.200" -> "http://192.168.9.200:8080/api/v1"
 * - "192.168.9.200:8080/api/v1" -> "http://192.168.9.200:8080/api/v1"
 */
export const normalizeServerHost = (input) => {
  if (!input || typeof input !== 'string') return '192.168.100.30:8080';
  let host = input.trim();

  // Strip protocol and trailing paths
  host = host.replace(/^https?:\/\//i, '');
  host = host.replace(/\/api\/v1\/?$/i, '');
  host = host.replace(/\/+$/, '');

  // Shorthand expansions for convenience
  if (host === '100.30' || host === '100.30:8080') {
    host = '192.168.100.30:8080';
  } else if (host === '9.200' || host === '9.200:8080') {
    host = '192.168.9.200:8080';
  } else if (host.startsWith('100.30:')) {
    host = host.replace(/^100\.30:/, '192.168.100.30:');
  } else if (host.startsWith('9.200:')) {
    host = host.replace(/^9\.200:/, '192.168.9.200:');
  }

  // Auto-append port 8080 if user enters an IP without a port
  // Matches pure IPv4: 192.168.100.30, 10.0.0.1, etc.
  if (/^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}$/.test(host)) {
    host = `${host}:8080`;
  }

  return host;
};

export const setBaseUrl = (url) => {
  if (url && typeof url === 'string') {
    const normalizedHost = normalizeServerHost(url);
    const finalApiUrl = `http://${normalizedHost}/api/v1`;
    currentBaseUrl = finalApiUrl;
    apiClient.defaults.baseURL = finalApiUrl;
    return finalApiUrl;
  }
  return currentBaseUrl;
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

// ECN Workflow API Methods
export const ecnStoreReceive = (data) => apiClient.post('/ecn/store/receive', data);
export const ecnSendToQc = (data) => apiClient.post('/ecn/store/send-to-qc', data);
export const ecnQcReceive = (data) => apiClient.post('/ecn/qc/receive', data);
export const ecnQcInspect = (data) => apiClient.post('/ecn/qc/inspect', data);
export const ecnCompleteRework = (data) => apiClient.post('/ecn/rework/complete', data);
export const ecnCompletePaint = (data) => apiClient.post('/ecn/paint/complete', data);
export const ecnCompleteAssembly = (data) => apiClient.post('/ecn/assembly/complete', data);
export const ecnRevert = (data) => apiClient.post('/ecn/revert', data);
export const getEcnRevertOptions = (params) => apiClient.get('/ecn/revert-options', { params });
export const mixedBulkIntake = (data) => apiClient.post('/ecn/mixed-bulk-intake', data);
export const mixedBulkRevert = (data) => apiClient.post('/ecn/mixed-bulk-revert', data);

export default apiClient;
