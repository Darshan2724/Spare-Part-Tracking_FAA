import axios from 'axios';

let currentBaseUrl = 'http://localhost:8080/api/v1';

const apiClient = axios.create({
  baseURL: currentBaseUrl,
  timeout: 10000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
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
