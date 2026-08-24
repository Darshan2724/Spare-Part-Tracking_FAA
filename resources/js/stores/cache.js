import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useAppCacheStore = defineStore('appCache', () => {
  const cache = ref(new Map());

  const get = (key) => {
    if (!cache.value.has(key)) return null;
    const entry = cache.value.get(key);
    const now = Date.now();
    const isStale = (now - entry.timestamp) > entry.ttl;
    return {
      data: entry.data,
      isStale,
      timestamp: entry.timestamp,
    };
  };

  const set = (key, data, ttlMs = 60000) => {
    cache.value.set(key, {
      data,
      timestamp: Date.now(),
      ttl: ttlMs,
    });
  };

  const invalidate = (prefixOrKey = '') => {
    if (!prefixOrKey) {
      cache.value.clear();
      return;
    }
    for (const key of cache.value.keys()) {
      if (key === prefixOrKey || key.startsWith(prefixOrKey) || key.includes(prefixOrKey)) {
        cache.value.delete(key);
      }
    }
  };

  /**
   * Stale-While-Revalidate (SWR) fetching helper
   */
  const swr = async (key, fetcher, { ttl = 60000, onCached, onFresh, onError } = {}) => {
    const cached = get(key);
    if (cached) {
      if (onCached) onCached(cached.data);
      // If cache is fresh, return early
      if (!cached.isStale) {
        return cached.data;
      }
    }

    try {
      const freshData = await fetcher();
      set(key, freshData, ttl);
      if (onFresh) onFresh(freshData);
      return freshData;
    } catch (err) {
      if (onError) onError(err);
      if (cached) return cached.data; // fallback to stale cache on network failure
      throw err;
    }
  };

  return {
    get,
    set,
    invalidate,
    swr,
  };
});
