import axios from 'axios';

let currentBaseUrl = 'http://10.17.214.175:8080/api/v1';

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

export const setBaseUrl = (url) => {
  if (url) {
    let cleanUrl = url.trim();
    if (!cleanUrl.startsWith('http://') && !cleanUrl.startsWith('https://')) {
      cleanUrl = `http://${cleanUrl}`;
    }
    if (!cleanUrl.endsWith('/api/v1')) {
      cleanUrl = cleanUrl.replace(/\/$/, '') + '/api/v1';
    }
    apiClient.defaults.baseURL = cleanUrl;
  }
};

export const setAuthToken = (token) => {
  if (token) {
    apiClient.defaults.headers.common['Authorization'] = `Bearer ${token}`;
  } else {
    delete apiClient.defaults.headers.common['Authorization'];
  }
};

export default apiClient;
