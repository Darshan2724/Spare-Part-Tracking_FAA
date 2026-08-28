import axios from 'axios';

// Default company LAN server fallback
const DEFAULT_HOST = 'http://192.168.100.36:8080/api/v1';
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

/**
 * Robust Server Base URL normalizer.
 * Supports: "192.168.9.200:8080", "http://192.168.9.200:8080", "192.168.9.200:8080/api/v1", etc.
 */
export const setBaseUrl = (url) => {
  if (url && typeof url === 'string') {
    let cleanUrl = url.trim();
    if (!cleanUrl.startsWith('http://') && !cleanUrl.startsWith('https://')) {
      cleanUrl = `http://${cleanUrl}`;
    }
    // Strip trailing /api/v1 or slashes to normalize root
    cleanUrl = cleanUrl.replace(/\/api\/v1\/?$/i, '').replace(/\/+$/, '');
    const finalApiUrl = `${cleanUrl}/api/v1`;
    currentBaseUrl = finalApiUrl;
    apiClient.defaults.baseURL = finalApiUrl;
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
