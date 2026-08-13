import React, { useState, useEffect, useRef, useCallback } from 'react';
import {
  StyleSheet,
  Text,
  View,
  TextInput,
  TouchableOpacity,
  SafeAreaView,
  ScrollView,
  Alert,
  ActivityIndicator,
  RefreshControl,
  Modal,
  StatusBar as RNStatusBar,
  PanResponder,
  Animated,
  Dimensions,
} from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { registerRootComponent } from 'expo';
import apiClient, { setAuthToken, setBaseUrl } from './src/api/client';

const SCREEN_WIDTH = Dimensions.get('window').width;
const SWIPE_THRESHOLD = SCREEN_WIDTH * 0.3; // 30% of screen width triggers action

/**
 * SwipeableQcItem: A card that can be swiped right (accept) or left (reject/rework).
 * - Arrival tab: swipe right = accept arrival, swipe left = reject & return
 * - Inspection tab: swipe right = approve, swipe left = reject
 */
function SwipeableQcItem({ item, qcSubTab, onAccept, onReject, onRework }) {
  const translateX = useRef(new Animated.Value(0)).current;
  const opacity = useRef(new Animated.Value(1)).current;

  const panResponder = useRef(
    PanResponder.create({
      onMoveShouldSetPanResponder: (_, { dx, dy }) => Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 10,
      onPanResponderMove: (_, { dx }) => {
        translateX.setValue(dx);
      },
      onPanResponderRelease: (_, { dx }) => {
        if (dx > SWIPE_THRESHOLD) {
          // Swipe RIGHT → Accept / Approve
          Animated.parallel([
            Animated.timing(translateX, { toValue: SCREEN_WIDTH, duration: 250, useNativeDriver: true }),
            Animated.timing(opacity, { toValue: 0, duration: 250, useNativeDriver: true }),
          ]).start(() => {
            translateX.setValue(0);
            opacity.setValue(1);
            onAccept(item);
          });
        } else if (dx < -SWIPE_THRESHOLD) {
          // Swipe LEFT → Reject / Return
          Animated.parallel([
            Animated.timing(translateX, { toValue: -SCREEN_WIDTH, duration: 250, useNativeDriver: true }),
            Animated.timing(opacity, { toValue: 0, duration: 250, useNativeDriver: true }),
          ]).start(() => {
            translateX.setValue(0);
            opacity.setValue(1);
            onReject(item);
          });
        } else {
          // Snap back
          Animated.spring(translateX, { toValue: 0, useNativeDriver: true }).start();
        }
      },
    })
  ).current;

  const bgColor = translateX.interpolate({
    inputRange: [-SCREEN_WIDTH / 2, 0, SCREEN_WIDTH / 2],
    outputRange: ['#ef4444', '#ffffff', '#10b981'],
    extrapolate: 'clamp',
  });

  return (
    <View style={{ position: 'relative', overflow: 'hidden', borderRadius: 12, marginBottom: 12 }}>
      {/* Background hint layer */}
      <Animated.View style={{
        position: 'absolute', inset: 0,
        backgroundColor: bgColor,
        borderRadius: 12,
        justifyContent: 'center',
        paddingHorizontal: 20,
        flexDirection: 'row',
        alignItems: 'center',
        justifyContent: 'space-between',
      }}>
        <Text style={{ color: '#fff', fontWeight: '800', fontSize: 15, letterSpacing: 1 }}>ACCEPT</Text>
        <Text style={{ color: '#fff', fontWeight: '800', fontSize: 15, letterSpacing: 1 }}>REJECT</Text>
      </Animated.View>

      {/* Swipeable card */}
      <Animated.View
        style={{ transform: [{ translateX }], opacity }}
        {...panResponder.panHandlers}
      >
        <View style={[swipeCardStyles.card]}>
          {/* Swipe hint */}
          <View style={swipeCardStyles.swipeHintRow}>
            <Text style={swipeCardStyles.swipeHintLeft}>‹ REJECT</Text>
            <Text style={swipeCardStyles.swipeHintTitle}>
              {qcSubTab === 'arrival' ? 'PHYSICAL ARRIVAL CHECK' : 'QUALITY INSPECTION'}
            </Text>
            <Text style={swipeCardStyles.swipeHintRight}>ACCEPT ›</Text>
          </View>

          <View style={swipeCardStyles.partRow}>
            <Text style={swipeCardStyles.partNo}>{item.standard_part_no || item.bom_item?.standard_part_no || `Item #${item.id}`}</Text>
            <Text style={swipeCardStyles.status}>{(item.status || '').toUpperCase()}</Text>
          </View>

          {item.bom_item?.project && (
            <Text style={swipeCardStyles.meta}>Project: {item.bom_item.project.name}</Text>
          )}
          <Text style={swipeCardStyles.meta}>
            Side: <Text style={{ fontWeight: '700' }}>{item.side || 'COMMON'}</Text>  |  Qty: <Text style={{ fontWeight: '700' }}>{item.received_quantity || item.quantity || 1}</Text>
          </Text>

          {/* For inspection tab, show extra Rework button */}
          {qcSubTab === 'inspection' && onRework && (
            <TouchableOpacity
              style={swipeCardStyles.reworkBtn}
              onPress={() => onRework(item)}
            >
              <Text style={swipeCardStyles.reworkBtnText}>TAP TO OPEN INSPECT FORM</Text>
            </TouchableOpacity>
          )}
        </View>
      </Animated.View>
    </View>
  );
}

const swipeCardStyles = StyleSheet.create({
  card: {
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 14,
    shadowColor: '#000',
    shadowOpacity: 0.1,
    shadowRadius: 8,
    elevation: 4,
    borderWidth: 1,
    borderColor: '#e5e7eb',
  },
  swipeHintRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 8,
    paddingBottom: 8,
    borderBottomWidth: 1,
    borderBottomColor: '#f3f4f6',
  },
  swipeHintLeft: { color: '#ef4444', fontSize: 11, fontWeight: '700' },
  swipeHintRight: { color: '#10b981', fontSize: 11, fontWeight: '700' },
  swipeHintTitle: { color: '#6b7280', fontSize: 11, flex: 1, textAlign: 'center' },
  partRow: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 },
  partNo: { fontSize: 16, fontWeight: '800', color: '#1e40af' },
  status: { fontSize: 10, fontWeight: '700', color: '#6b7280', backgroundColor: '#f3f4f6', paddingHorizontal: 6, paddingVertical: 2, borderRadius: 4 },
  meta: { fontSize: 12, color: '#6b7280', marginTop: 3 },
  reworkBtn: {
    marginTop: 10,
    backgroundColor: '#fef3c7',
    borderWidth: 1,
    borderColor: '#f59e0b',
    borderRadius: 8,
    paddingVertical: 8,
    alignItems: 'center',
  },
  reworkBtnText: { color: '#92400e', fontWeight: '700', fontSize: 13 },
});

function App() {
  const [token, setToken] = useState(null);
  const [user, setUser] = useState(null);
  const [userRole, setUserRole] = useState('');
  const [serverHost, setServerHost] = useState('192.168.1.31:8080');
  const [email, setEmail] = useState('admin@sparetrack.internal');
  const [password, setPassword] = useState('password123');

  const [activeTab, setActiveTab] = useState('dashboard');
  const [storeSubTab, setStoreSubTab] = useState('pending'); // 'pending' | 'history'
  const [qcSubTab, setQcSubTab] = useState('arrival'); // 'arrival' | 'inspection'
  const [summary, setSummary] = useState(null);
  const [items, setItems] = useState([]);
  const [historyItems, setHistoryItems] = useState([]);
  const [projects, setProjects] = useState([]);
  const [loading, setLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');

  // Search & Filter state
  const [searchQuery, setSearchQuery] = useState('');
  const [selectedSide, setSelectedSide] = useState('');
  const [selectedProject, setSelectedProject] = useState('');
  const [showFilterModal, setShowFilterModal] = useState(false);
  const searchTimer = useRef(null);

  // Store Receive Modal state
  const [showReceiveModal, setShowReceiveModal] = useState(false);
  const [selectedItemForReceive, setSelectedItemForReceive] = useState(null);
  const [receiveSide, setReceiveSide] = useState('RH');
  const [receiveQty, setReceiveQty] = useState('1');
  const [deliveryNote, setDeliveryNote] = useState('');

  // QC Inspection Modal state
  const [showQcModal, setShowQcModal] = useState(false);
  const [selectedQcItem, setSelectedQcItem] = useState(null);
  const [qcResult, setQcResult] = useState('approved'); // 'approved' | 'rejected' | 'rework' | 'partial'
  const [qcApprovedQty, setQcApprovedQty] = useState('1');
  const [qcRejectedQty, setQcRejectedQty] = useState('0');
  const [qcReworkQty, setQcReworkQty] = useState('0');
  const [qcReason, setQcReason] = useState('');
  const [qcRemarks, setQcRemarks] = useState('');

  // 30s Polling Loop for live real-time updates
  useEffect(() => {
    if (!token) return;
    const interval = setInterval(() => {
      loadData(activeTab, false);
    }, 30000);
    return () => clearInterval(interval);
  }, [token, activeTab, storeSubTab, searchQuery, selectedSide, selectedProject]);

  const handleLogin = async () => {
    if (!email || !password) {
      Alert.alert('Missing Fields', 'Please enter email and password.');
      return;
    }

    setErrorMsg('');
    setLoading(true);

    try {
      if (serverHost) {
        setBaseUrl(serverHost);
      }

      const res = await apiClient.post('/auth/login', { email, password });
      const userToken = res.data.token;
      const loggedUser = res.data.user;
      const role = loggedUser?.roles?.[0]?.name || res.data.role || 'USER';

      setToken(userToken);
      setUser(loggedUser);
      setUserRole(role);
      setAuthToken(userToken);

      // Auto-set tab based on role
      if (role === 'STORE') setActiveTab('store');
      else if (role === 'QC') setActiveTab('qc');
      else if (role === 'REWORK') setActiveTab('rework');
      else if (role === 'PAINT') setActiveTab('paint');
      else if (role === 'ASSEMBLY') setActiveTab('assembly');
      else if (role === 'PURCHASE') setActiveTab('purchase');
      else setActiveTab('dashboard');

      await loadData(role === 'STORE' ? 'store' : role === 'QC' ? 'qc' : 'dashboard');
    } catch (err) {
      const msg = err.response?.data?.message || err.message || 'Could not connect to server.';
      setErrorMsg(`Connection Error: ${msg}\n\nMake sure server host IP is correct and backend is running.`);
      Alert.alert('Login Failed', msg);
    } finally {
      setLoading(false);
    }
  };

  const handleLogout = () => {
    Alert.alert('Logout', 'Are you sure you want to log out?', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Logout',
        style: 'destructive',
        onPress: () => {
          setToken(null);
          setUser(null);
          setUserRole('');
          setAuthToken(null);
          setSummary(null);
          setItems([]);
          setHistoryItems([]);
        }
      }
    ]);
  };

  const extractArray = (resData) => {
    if (!resData) return [];
    if (Array.isArray(resData)) return resData;
    if (Array.isArray(resData.data)) return resData.data;
    if (Array.isArray(resData.data?.data)) return resData.data.data;
    if (Array.isArray(resData.items)) return resData.items;
    if (Array.isArray(resData.items?.data)) return resData.items.data;
    if (Array.isArray(resData.queue)) return resData.queue;
    if (Array.isArray(resData.queue?.data)) return resData.queue.data;
    return [];
  };

  const loadData = async (tab = activeTab, showSpinner = true) => {
    if (showSpinner) setLoading(true);
    try {
      const params = { per_page: 100 };
      if (searchQuery) params.search = searchQuery;
      if (selectedSide) params.side = selectedSide;
      if (selectedProject) params.project_id = selectedProject;

      if (tab === 'dashboard') {
        const res = await apiClient.get('/dashboard/summary', { params });
        setSummary(res.data.summary || res.data);
      } else if (tab === 'store') {
        if (storeSubTab === 'history') {
          const res = await apiClient.get('/store/history', { params });
          setHistoryItems(extractArray(res.data));
        } else {
          const res = await apiClient.get('/store/items', { params });
          setItems(extractArray(res.data));
          if (res.data.projects) setProjects(res.data.projects);
        }
      } else {
        const endpoints = {
          qc: '/qc/queue',
          rework: '/rework/items',
          paint: '/paint/queue',
          assembly: '/assembly/queue',
          purchase: '/purchase/items',
        };
        const endpoint = endpoints[tab] || '/store/items';
        const res = await apiClient.get(endpoint, { params });
        setItems(extractArray(res.data));
      }
    } catch (err) {
      console.log(`Error loading ${tab} data:`, err);
    } finally {
      if (showSpinner) setLoading(false);
      setRefreshing(false);
    }
  };

  const handleSearchChange = (text) => {
    setSearchQuery(text);
    clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => {
      loadData(activeTab);
    }, 400);
  };

  const onRefresh = () => {
    setRefreshing(true);
    loadData(activeTab);
  };

  const handleTabChange = (tab) => {
    setActiveTab(tab);
    loadData(tab);
  };

  // --- STORE ACTIONS ---
  const openReceiveModal = (item, defaultSide = 'RH') => {
    setSelectedItemForReceive(item);
    setReceiveSide(defaultSide);
    const pending = item.side_stats?.[defaultSide]?.pending ?? 1;
    setReceiveQty(String(pending > 0 ? pending : 1));
    setDeliveryNote(`DN-${Date.now().toString().slice(-4)}`);
    setShowReceiveModal(true);
  };

  const submitStoreReceive = async () => {
    if (!selectedItemForReceive) return;
    const qty = parseInt(receiveQty, 10);
    if (isNaN(qty) || qty <= 0) {
      Alert.alert('Invalid Quantity', 'Please enter a valid quantity greater than 0.');
      return;
    }

    try {
      await apiClient.post('/store/receipts', {
        project_id: selectedItemForReceive.project_id,
        delivery_note_number: deliveryNote,
        items: [
          {
            bom_item_id: selectedItemForReceive.id,
            side: receiveSide,
            received_quantity: qty,
          }
        ]
      });

      setShowReceiveModal(false);
      Alert.alert('Stock Received', `Successfully received ${qty} pcs (${receiveSide}) for ${selectedItemForReceive.standard_part_no}`);
      loadData('store');
    } catch (err) {
      Alert.alert('Receive Failed', err.response?.data?.message || 'Could not record store receipt.');
    }
  };

  const handleSendToQc = async (itemId) => {
    try {
      await apiClient.post(`/store/items/${itemId}/send-to-qc`);
      Alert.alert('Dispatched to QC', 'Item sent to Quality Control queue.');
      loadData('store');
    } catch (err) {
      Alert.alert('Error', err.response?.data?.message || 'Failed to dispatch item to QC.');
    }
  };

  const handleRevertReceipt = (historyItem) => {
    Alert.alert(
      'Revert Stock Receipt',
      `Are you sure you want to revert receipt of ${historyItem.received_quantity} pcs (${historyItem.side}) for ${historyItem.bom_item?.standard_part_no || 'this part'}?\n\nThis will undo the receipt and restore pending arrival stock.`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Revert / Undo',
          style: 'destructive',
          onPress: async () => {
            try {
              const res = await apiClient.post(`/store/items/${historyItem.id}/revert`);
              Alert.alert('Receipt Reverted', res.data.message || 'Stock receipt successfully undone.');
              loadData('store');
            } catch (err) {
              Alert.alert('Revert Failed', err.response?.data?.message || 'Could not revert stock receipt.');
            }
          }
        }
      ]
    );
  };

  // --- QC ACTIONS ---
  const handleConfirmQcPhysicalArrival = async (receiptItemId) => {
    try {
      await apiClient.post('/qc/receive', { receipt_item_id: receiptItemId });
      Alert.alert('Physical Arrival Confirmed', 'Item is now ready for Quality Inspection.');
      loadData('qc');
    } catch (err) {
      Alert.alert('Action Failed', err.response?.data?.message || 'Could not confirm physical QC arrival.');
    }
  };

  const handleRejectQcPhysicalArrival = (receiptItemId) => {
    Alert.alert(
      'Reject Physical Arrival',
      'Are you sure you want to reject physical arrival of this part?\n\nThis will send the item back to Store indicating stock was NOT received in QC bay.',
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Reject & Return to Store',
          style: 'destructive',
          onPress: async () => {
            try {
              const res = await apiClient.post('/qc/reject-arrival', { receipt_item_id: receiptItemId });
              Alert.alert('Arrival Rejected', res.data.message || 'Item returned to store verification.');
              loadData('qc');
            } catch (err) {
              Alert.alert('Action Failed', err.response?.data?.message || 'Could not reject physical QC arrival.');
            }
          }
        }
      ]
    );
  };

  const openQcModal = (item, resultType) => {
    setSelectedQcItem(item);
    setQcResult(resultType);
    const qty = item.received_quantity || 1;
    if (resultType === 'approved') {
      setQcApprovedQty(String(qty));
      setQcRejectedQty('0');
      setQcReworkQty('0');
    } else if (resultType === 'rejected') {
      setQcApprovedQty('0');
      setQcRejectedQty(String(qty));
      setQcReworkQty('0');
    } else if (resultType === 'rework') {
      setQcApprovedQty('0');
      setQcRejectedQty('0');
      setQcReworkQty(String(qty));
    } else {
      setQcApprovedQty('0');
      setQcRejectedQty('0');
      setQcReworkQty('0');
    }
    setQcReason('');
    setQcRemarks('');
    setShowQcModal(true);
  };

  const submitQcInspection = async () => {
    if (!selectedQcItem) return;
    const avail = selectedQcItem.received_quantity || 1;
    const app = parseInt(qcApprovedQty, 10) || 0;
    const rej = parseInt(qcRejectedQty, 10) || 0;
    const rew = parseInt(qcReworkQty, 10) || 0;

    if (qcResult === 'partial') {
      if (app + rej + rew !== avail) {
        Alert.alert('Quantity Error', `Sum of Approved (${app}) + Rework (${rew}) + Rejected (${rej}) must equal Available (${avail}).`);
        return;
      }
    }

    try {
      await apiClient.post('/qc/inspect', {
        receipt_item_id: selectedQcItem.id,
        side: selectedQcItem.side || 'COMMON',
        inspected_quantity: avail,
        result: qcResult,
        approved_quantity: app,
        rejected_quantity: rej,
        rework_quantity: rew,
        rejection_reason: qcReason,
        rework_reason: qcReason,
        remarks: qcRemarks,
      });

      setShowQcModal(false);
      Alert.alert('QC Inspection Recorded', `Result: ${qcResult.toUpperCase()} for ${selectedQcItem.bom_item?.standard_part_no || 'item'}`);
      loadData('qc');
    } catch (err) {
      Alert.alert('Inspection Failed', err.response?.data?.message || 'Could not record QC inspection.');
    }
  };

  // --- REWORK ACTIONS ---
  const handleStartRework = async (itemId) => {
    try {
      await apiClient.post(`/rework/items/${itemId}/start`);
      Alert.alert('Rework Started', 'Item status updated to In Progress.');
      loadData('rework');
    } catch (err) {
      Alert.alert('Error', err.response?.data?.message || 'Could not start rework.');
    }
  };

  const handleCompleteRework = async (itemId) => {
    try {
      await apiClient.post(`/rework/items/${itemId}/complete`);
      Alert.alert('Rework Completed', 'Item returned to Quality Control for reinspection.');
      loadData('rework');
    } catch (err) {
      Alert.alert('Error', err.response?.data?.message || 'Could not complete rework.');
    }
  };

  if (!token) {
    return (
      <SafeAreaView style={styles.container}>
        <StatusBar style="dark" />
        <ScrollView contentContainerStyle={styles.scrollContent} keyboardShouldPersistTaps="handled">
          <View style={styles.loginBox}>
            <Text style={styles.logoIcon}>⚙️</Text>
            <Text style={styles.title}>SpareTrack MES</Text>
            <Text style={styles.subtitle}>Mobile Manufacturing Terminal</Text>

            {errorMsg ? (
              <View style={styles.errorContainer}>
                <Text style={styles.errorText}>{errorMsg}</Text>
              </View>
            ) : null}

            <Text style={styles.label}>Server Host / IP</Text>
            <TextInput
              style={styles.input}
              value={serverHost}
              onChangeText={setServerHost}
              placeholder="e.g. 192.168.100.60:8080"
              autoCapitalize="none"
              autoCorrect={false}
            />

            <Text style={styles.label}>Email Address</Text>
            <TextInput
              style={styles.input}
              value={email}
              onChangeText={setEmail}
              placeholder="admin@sparetrack.internal"
              autoCapitalize="none"
              keyboardType="email-address"
              autoCorrect={false}
            />

            <Text style={styles.label}>Password</Text>
            <TextInput
              style={styles.input}
              value={password}
              onChangeText={setPassword}
              secureTextEntry
              placeholder="••••••••"
            />

            <TouchableOpacity style={styles.button} onPress={handleLogin} disabled={loading}>
              {loading ? (
                <ActivityIndicator color="#ffffff" />
              ) : (
                <Text style={styles.buttonText}>Sign In to Mobile Terminal</Text>
              )}
            </TouchableOpacity>
          </View>
        </ScrollView>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.container}>
      <StatusBar style="dark" />
      {/* Top Header */}
      <View style={styles.header}>
        <View>
          <Text style={styles.headerTitle}>SpareTrack Mobile</Text>
          <Text style={styles.userSubtitle}>
            👤 {user?.name || 'User'} • <Text style={styles.roleBadge}>{userRole}</Text>
          </Text>
        </View>
        <TouchableOpacity style={styles.logoutBtn} onPress={handleLogout}>
          <Text style={styles.logoutBtnText}>Logout</Text>
        </TouchableOpacity>
      </View>

      {/* Navigation Tabs Bar */}
      <ScrollView horizontal showsHorizontalScrollIndicator={false} style={styles.tabsContainer}>
        {['ADMIN', 'MANAGER'].includes(userRole) && (
          <TouchableOpacity
            style={[styles.tab, activeTab === 'dashboard' && styles.activeTab]}
            onPress={() => handleTabChange('dashboard')}>
            <Text style={[styles.tabText, activeTab === 'dashboard' && styles.activeTabText]}>📊 Summary</Text>
          </TouchableOpacity>
        )}
        {['ADMIN', 'MANAGER', 'STORE'].includes(userRole) && (
          <TouchableOpacity
            style={[styles.tab, activeTab === 'store' && styles.activeTab]}
            onPress={() => handleTabChange('store')}>
            <Text style={[styles.tabText, activeTab === 'store' && styles.activeTabText]}>📦 Store</Text>
          </TouchableOpacity>
        )}
        {['ADMIN', 'MANAGER', 'QC'].includes(userRole) && (
          <TouchableOpacity
            style={[styles.tab, activeTab === 'qc' && styles.activeTab]}
            onPress={() => handleTabChange('qc')}>
            <Text style={[styles.tabText, activeTab === 'qc' && styles.activeTabText]}>🔍 QC Queue</Text>
          </TouchableOpacity>
        )}
        {['ADMIN', 'MANAGER', 'REWORK'].includes(userRole) && (
          <TouchableOpacity
            style={[styles.tab, activeTab === 'rework' && styles.activeTab]}
            onPress={() => handleTabChange('rework')}>
            <Text style={[styles.tabText, activeTab === 'rework' && styles.activeTabText]}>🛠️ Rework</Text>
          </TouchableOpacity>
        )}
        {['ADMIN', 'MANAGER', 'PAINT'].includes(userRole) && (
          <TouchableOpacity
            style={[styles.tab, activeTab === 'paint' && styles.activeTab]}
            onPress={() => handleTabChange('paint')}>
            <Text style={[styles.tabText, activeTab === 'paint' && styles.activeTabText]}>🎨 Paint</Text>
          </TouchableOpacity>
        )}
        {['ADMIN', 'MANAGER', 'ASSEMBLY'].includes(userRole) && (
          <TouchableOpacity
            style={[styles.tab, activeTab === 'assembly' && styles.activeTab]}
            onPress={() => handleTabChange('assembly')}>
            <Text style={[styles.tabText, activeTab === 'assembly' && styles.activeTabText]}>⚙️ Assembly</Text>
          </TouchableOpacity>
        )}
        {['ADMIN', 'MANAGER', 'PURCHASE'].includes(userRole) && (
          <TouchableOpacity
            style={[styles.tab, activeTab === 'purchase' && styles.activeTab]}
            onPress={() => handleTabChange('purchase')}>
            <Text style={[styles.tabText, activeTab === 'purchase' && styles.activeTabText]}>🛒 Purchase</Text>
          </TouchableOpacity>
        )}
      </ScrollView>

      {/* Search & Filter Bar (on Store and QC tabs) */}
      {['store', 'qc'].includes(activeTab) && (
        <View style={styles.searchBarContainer}>
          <TextInput
            style={styles.searchInput}
            placeholder={`🔍 Search ${activeTab.toUpperCase()} items...`}
            value={searchQuery}
            onChangeText={handleSearchChange}
          />
          <TouchableOpacity style={styles.filterBtn} onPress={() => setShowFilterModal(true)}>
            <Text style={styles.filterBtnText}>🔽 Filters</Text>
          </TouchableOpacity>
        </View>
      )}

      {/* Active Filter Chips */}
      {(selectedSide || selectedProject) ? (
        <View style={styles.chipsContainer}>
          {selectedSide ? (
            <TouchableOpacity style={styles.chip} onPress={() => { setSelectedSide(''); loadData(activeTab); }}>
              <Text style={styles.chipText}>Side: {selectedSide} ✕</Text>
            </TouchableOpacity>
          ) : null}
          {selectedProject ? (
            <TouchableOpacity style={styles.chip} onPress={() => { setSelectedProject(''); loadData(activeTab); }}>
              <Text style={styles.chipText}>Project Filter Active ✕</Text>
            </TouchableOpacity>
          ) : null}
        </View>
      ) : null}

      {/* Store Sub-Tabs (Pending vs History & Revert) */}
      {activeTab === 'store' && (
        <View style={styles.subTabsContainer}>
          <TouchableOpacity
            style={[styles.subTab, storeSubTab === 'pending' && styles.activeSubTab]}
            onPress={() => { setStoreSubTab('pending'); loadData('store'); }}>
            <Text style={[styles.subTabText, storeSubTab === 'pending' && styles.activeSubTabText]}>📦 Pending Arrival</Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.subTab, storeSubTab === 'history' && styles.activeSubTab]}
            onPress={() => { setStoreSubTab('history'); loadData('store'); }}>
            <Text style={[styles.subTabText, storeSubTab === 'history' && styles.activeSubTabText]}>📜 Receipt History & Revert</Text>
          </TouchableOpacity>
        </View>
      )}

      {/* QC Sub-Tabs (1. Physical Arrival vs 2. Quality Inspection) */}
      {activeTab === 'qc' && (
        <View style={styles.subTabsContainer}>
          <TouchableOpacity
            style={[styles.subTab, qcSubTab === 'arrival' && styles.activeSubTab]}
            onPress={() => { setQcSubTab('arrival'); loadData('qc'); }}>
            <Text style={[styles.subTabText, qcSubTab === 'arrival' && styles.activeSubTabText]}>📦 1. Physical Arrival</Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.subTab, qcSubTab === 'inspection' && styles.activeSubTab]}
            onPress={() => { setQcSubTab('inspection'); loadData('qc'); }}>
            <Text style={[styles.subTabText, qcSubTab === 'inspection' && styles.activeSubTabText]}>🔬 2. Quality Inspection</Text>
          </TouchableOpacity>
        </View>
      )}

      {/* Main Content Scroll View */}
      <ScrollView
        style={styles.content}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}>
        {loading && !refreshing ? (
          <ActivityIndicator size="large" color="#2563eb" style={{ marginTop: 40 }} />
        ) : activeTab === 'dashboard' ? (
          <View style={styles.cardContainer}>
            <View style={[styles.card, { backgroundColor: '#2563eb' }]}>
              <Text style={styles.cardLabel}>Active Projects</Text>
              <Text style={styles.cardValue}>{summary?.total_projects || 0}</Text>
            </View>
            <View style={[styles.card, { backgroundColor: '#10b981' }]}>
              <Text style={styles.cardLabel}>Parts Received</Text>
              <Text style={styles.cardValue}>{summary?.total_received || 0}</Text>
            </View>
            <View style={[styles.card, { backgroundColor: '#f59e0b' }]}>
              <Text style={styles.cardLabel}>Awaiting QC</Text>
              <Text style={styles.cardValue}>{summary?.awaiting_qc || 0}</Text>
            </View>
            <View style={[styles.card, { backgroundColor: '#ef4444' }]}>
              <Text style={styles.cardLabel}>Purchase Queue</Text>
              <Text style={styles.cardValue}>{summary?.pending_purchase || 0}</Text>
            </View>
          </View>
        ) : activeTab === 'store' && storeSubTab === 'history' ? (
          // STORE RECEIPT HISTORY & REVERT VIEW
          <View style={styles.listContainer}>
            <Text style={styles.sectionHeader}>
              RECENT STORE RECEIPTS ({historyItems.length})
            </Text>
            {historyItems.length === 0 ? (
              <View style={styles.emptyState}>
                <Text style={styles.emptyStateText}>No receipt history recorded yet.</Text>
              </View>
            ) : (
              historyItems.map((item) => (
                <View key={item.id} style={styles.itemCard}>
                  <View style={styles.itemHeader}>
                    <Text style={styles.itemPartNo}>{item.bom_item?.standard_part_no || `Item #${item.id}`}</Text>
                    <Text style={styles.itemStatus}>{(item.status || 'Received').toUpperCase()}</Text>
                  </View>
                  <Text style={styles.itemSubText}>Side: {item.side} | Qty Received: {item.received_quantity}</Text>
                  <Text style={styles.itemSubText}>Date: {new Date(item.created_at).toLocaleString()}</Text>
                  
                  {['received', 'sent_to_qc'].includes(item.status) && (
                    <TouchableOpacity style={styles.revertBtn} onPress={() => handleRevertReceipt(item)}>
                      <Text style={styles.revertBtnText}>↩️ Revert / Undo Receipt</Text>
                    </TouchableOpacity>
                  )}
                </View>
              ))
            )}
          </View>
        ) : (
          // WORKFLOW QUEUES LIST VIEW (Store, QC, Rework, Paint, Assembly, Purchase)
          <View style={styles.listContainer}>
            <Text style={styles.sectionHeader}>
              {activeTab === 'qc' ? (qcSubTab === 'arrival' ? 'PHYSICAL ARRIVAL QUEUE' : 'QC INSPECTION QUEUE') : activeTab.toUpperCase() + ' WORKFLOW QUEUE'} ({
                (Array.isArray(items) ? items : []).filter(item => {
                  if (activeTab === 'qc') {
                    if (qcSubTab === 'arrival') return ['received', 'sent_to_qc'].includes(item.status);
                    if (qcSubTab === 'inspection') return item.status === 'qc_received';
                  }
                  return true;
                }).length
              })
            </Text>

            {(Array.isArray(items) ? items : []).filter(item => {
              if (activeTab === 'qc') {
                if (qcSubTab === 'arrival') return ['received', 'sent_to_qc'].includes(item.status);
                if (qcSubTab === 'inspection') return item.status === 'qc_received';
              }
              return true;
            }).length === 0 ? (
              <View style={styles.emptyState}>
                <Text style={styles.emptyStateText}>
                  {activeTab === 'qc' 
                    ? (qcSubTab === 'arrival' ? 'No parts pending physical arrival in QC.' : 'No parts ready for Quality Inspection.')
                    : `No pending items in ${activeTab} queue.`}
                </Text>
              </View>
            ) : (
              (Array.isArray(items) ? items : [])
                .filter(item => {
                  if (activeTab === 'qc') {
                    if (qcSubTab === 'arrival') return ['received', 'sent_to_qc'].includes(item.status);
                    if (qcSubTab === 'inspection') return item.status === 'qc_received';
                  }
                  return true;
                })
                .map((item, idx) => (
                  <View key={item.id || idx} style={styles.itemCard}>
                    <View style={styles.itemHeader}>
                      <Text style={styles.itemPartNo}>{item.standard_part_no || item.bom_item?.standard_part_no || `Item #${item.id}`}</Text>
                      <Text style={styles.itemStatus}>
                        {(item.status || (item.side_stats ? 'BOM IMPORTED' : 'PENDING')).toUpperCase()}
                      </Text>
                    </View>

                    {item.project && <Text style={styles.itemSubText}>📁 Project: {item.project.name || item.project.project_code}</Text>}
                    {item.bom_item?.project && <Text style={styles.itemSubText}>📁 Project: {item.bom_item.project.name}</Text>}
                    {item.size && <Text style={styles.itemSubText}>📏 Size: {item.size}</Text>}

                    {/* Store Item Side Requirements */}
                    {item.side_stats ? (
                      <View style={styles.statsRow}>
                        {Object.entries(item.side_stats)
                          .filter(([side]) => !selectedSide || side === selectedSide)
                          .map(([side, st]) => (
                            <View key={side} style={styles.sideCardBox}>
                              <Text style={styles.statBadge}>{side} (Req: {st.required} | Rec: {st.received} | Pending: {st.pending})</Text>
                              {st.pending > 0 && (
                                <TouchableOpacity style={styles.smallReceiveBtn} onPress={() => openReceiveModal(item, side)}>
                                  <Text style={styles.smallReceiveBtnText}>📥 Receive {side} Stock</Text>
                                </TouchableOpacity>
                              )}
                            </View>
                          ))}
                      </View>
                    ) : (
                      <Text style={styles.itemSubText}>Side: {item.side || 'COMMON'} | Qty: {item.received_quantity || item.quantity || 1}</Text>
                    )}

                    {/* QC QUEUE DUAL-TAB ACTIONS - SWIPEABLE */}
                     {activeTab === 'qc' ? (
                       <>
                         {/* ARRIVAL TAB swipeable */}
                         {qcSubTab === 'arrival' && ['received', 'sent_to_qc'].includes(item.status) && (
                           <SwipeableQcItem
                             key={`arrival-${item.id}`}
                             item={item}
                             qcSubTab="arrival"
                             onAccept={(it) => handleConfirmQcPhysicalArrival(it.id)}
                             onReject={(it) => handleRejectQcPhysicalArrival(it.id)}
                           />
                         )}
                         {/* INSPECTION TAB swipeable */}
                         {qcSubTab === 'inspection' && item.status === 'qc_received' && (
                           <SwipeableQcItem
                             key={`insp-${item.id}`}
                             item={item}
                             qcSubTab="inspection"
                             onAccept={(it) => openQcModal(it, 'approved')}
                             onReject={(it) => openQcModal(it, 'rejected')}
                             onRework={(it) => openQcModal(it, 'rework')}
                           />
                         )}
                       </>
                     ) : null}

                  {/* REWORK QUEUE ACTIONS */}
                  {activeTab === 'rework' && (
                    <View style={{ flexDirection: 'row', gap: 8, marginTop: 10 }}>
                      {item.status === 'pending' && (
                        <TouchableOpacity style={[styles.actionBtn, { flex: 1, backgroundColor: '#f59e0b' }]} onPress={() => handleStartRework(item.id)}>
                          <Text style={styles.actionBtnText}>▶ Start Rework</Text>
                        </TouchableOpacity>
                      )}
                      {item.status === 'in_progress' && (
                        <TouchableOpacity style={[styles.actionBtn, { flex: 1, backgroundColor: '#10b981' }]} onPress={() => handleCompleteRework(item.id)}>
                          <Text style={styles.actionBtnText}>✅ Complete & Return to QC</Text>
                        </TouchableOpacity>
                      )}
                    </View>
                  )}
                </View>
              ))
            )}
          </View>
        )}
      </ScrollView>

      {/* FILTER MODAL */}
      <Modal visible={showFilterModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Filter {activeTab.toUpperCase()} Items</Text>
            
            <Text style={styles.label}>Select Side Requirement</Text>
            <View style={{ flexDirection: 'row', gap: 6, marginBottom: 14 }}>
              {['', 'RH', 'LH', 'COMMON'].map((s) => (
                <TouchableOpacity
                  key={s}
                  style={[styles.chipBtn, selectedSide === s && styles.chipBtnActive]}
                  onPress={() => setSelectedSide(s)}>
                  <Text style={[styles.chipBtnText, selectedSide === s && styles.chipBtnTextActive]}>
                    {s || 'ALL'}
                  </Text>
                </TouchableOpacity>
              ))}
            </View>

            {projects.length > 0 && (
              <View style={{ marginBottom: 16 }}>
                <Text style={styles.label}>Select Project</Text>
                <ScrollView horizontal showsHorizontalScrollIndicator={false} style={{ flexDirection: 'row' }}>
                  <TouchableOpacity
                    style={[styles.chipBtn, selectedProject === '' && styles.chipBtnActive, { marginRight: 6 }]}
                    onPress={() => setSelectedProject('')}>
                    <Text style={[styles.chipBtnText, selectedProject === '' && styles.chipBtnTextActive]}>
                      All Projects
                    </Text>
                  </TouchableOpacity>
                  {projects.map((p) => (
                    <TouchableOpacity
                      key={p.id}
                      style={[styles.chipBtn, selectedProject === p.id && styles.chipBtnActive, { marginRight: 6 }]}
                      onPress={() => setSelectedProject(p.id)}>
                      <Text style={[styles.chipBtnText, selectedProject === p.id && styles.chipBtnTextActive]}>
                        {p.project_code || p.name}
                      </Text>
                    </TouchableOpacity>
                  ))}
                </ScrollView>
              </View>
            )}

            <TouchableOpacity style={styles.button} onPress={() => { setShowFilterModal(false); loadData(activeTab); }}>
              <Text style={styles.buttonText}>Apply Filters</Text>
            </TouchableOpacity>
          </View>
        </View>
      </Modal>

      {/* STORE RECEIVE CONFIRMATION MODAL */}
      <Modal visible={showReceiveModal} animationType="fade" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Confirm Store Stock Receipt</Text>
            <Text style={styles.itemPartNo}>{selectedItemForReceive?.standard_part_no}</Text>
            <Text style={styles.itemSubText}>Side: {receiveSide} | Delivery Note: {deliveryNote}</Text>

            <Text style={[styles.label, { marginTop: 12 }]}>Received Quantity</Text>
            <TextInput
              style={styles.input}
              value={receiveQty}
              onChangeText={setReceiveQty}
              keyboardType="number-pad"
            />

            <View style={{ flexDirection: 'row', gap: 8, marginTop: 12 }}>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowReceiveModal(false)}>
                <Text style={styles.buttonText}>Cancel</Text>
              </TouchableOpacity>
              <TouchableOpacity style={[styles.button, { flex: 1 }]} onPress={submitStoreReceive}>
                <Text style={styles.buttonText}>Confirm Receipt</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>

      {/* QC INSPECTION MODAL */}
      <Modal visible={showQcModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Record QC Inspection ({qcResult.toUpperCase()})</Text>
            <Text style={styles.itemPartNo}>{selectedQcItem?.bom_item?.standard_part_no}</Text>
            <Text style={styles.itemSubText}>Available: {selectedQcItem?.received_quantity || 1} pcs ({selectedQcItem?.side})</Text>

            {qcResult === 'rejected' || qcResult === 'rework' ? (
              <View>
                <Text style={[styles.label, { marginTop: 10 }]}>Reason for {qcResult.toUpperCase()}</Text>
                <TextInput
                  style={styles.input}
                  value={qcReason}
                  onChangeText={setQcReason}
                  placeholder="e.g. Surface dent, dimensional mismatch"
                />
              </View>
            ) : null}

            <Text style={styles.label}>Remarks / Inspection Notes</Text>
            <TextInput
              style={styles.input}
              value={qcRemarks}
              onChangeText={setQcRemarks}
              placeholder="Optional remarks"
            />

            <View style={{ flexDirection: 'row', gap: 8, marginTop: 12 }}>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowQcModal(false)}>
                <Text style={styles.buttonText}>Cancel</Text>
              </TouchableOpacity>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: qcResult === 'rejected' ? '#ef4444' : qcResult === 'rework' ? '#f59e0b' : '#10b981' }]} onPress={submitQcInspection}>
                <Text style={styles.buttonText}>Submit QC Result</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>
    </SafeAreaView>
  );
}

export default App;
registerRootComponent(App);

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f8fafc',
    paddingTop: RNStatusBar.currentHeight || 0,
  },
  scrollContent: {
    flexGrow: 1,
    justifyContent: 'center',
    padding: 20,
  },
  loginBox: {
    backgroundColor: '#ffffff',
    padding: 24,
    borderRadius: 16,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.05,
    shadowRadius: 8,
    elevation: 3,
  },
  logoIcon: {
    fontSize: 40,
    textAlign: 'center',
    marginBottom: 8,
  },
  title: {
    fontSize: 24,
    fontWeight: 'bold',
    color: '#0f172a',
    textAlign: 'center',
  },
  subtitle: {
    fontSize: 13,
    color: '#64748b',
    textAlign: 'center',
    marginBottom: 24,
  },
  errorContainer: {
    backgroundColor: '#fef2f2',
    borderColor: '#fca5a5',
    borderWidth: 1,
    borderRadius: 8,
    padding: 12,
    marginBottom: 16,
  },
  errorText: {
    color: '#991b1b',
    fontSize: 12,
  },
  label: {
    fontSize: 13,
    fontWeight: '600',
    color: '#334155',
    marginBottom: 6,
  },
  input: {
    backgroundColor: '#f8fafc',
    borderWidth: 1,
    borderColor: '#cbd5e1',
    borderRadius: 8,
    padding: 12,
    marginBottom: 12,
    fontSize: 15,
    color: '#0f172a',
  },
  button: {
    backgroundColor: '#2563eb',
    padding: 12,
    borderRadius: 8,
    alignItems: 'center',
  },
  buttonText: {
    color: '#ffffff',
    fontSize: 14,
    fontWeight: 'bold',
  },
  header: {
    padding: 14,
    backgroundColor: '#ffffff',
    borderBottomWidth: 1,
    borderColor: '#e2e8f0',
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  headerTitle: {
    fontSize: 18,
    fontWeight: 'bold',
    color: '#0f172a',
  },
  userSubtitle: {
    fontSize: 12,
    color: '#64748b',
    marginTop: 2,
  },
  roleBadge: {
    color: '#2563eb',
    fontWeight: 'bold',
  },
  logoutBtn: {
    backgroundColor: '#fef2f2',
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 6,
    borderWidth: 1,
    borderColor: '#fca5a5',
  },
  logoutBtnText: {
    color: '#ef4444',
    fontWeight: 'bold',
    fontSize: 13,
  },
  tabsContainer: {
    backgroundColor: '#ffffff',
    borderBottomWidth: 1,
    borderColor: '#e2e8f0',
    maxHeight: 46,
    paddingHorizontal: 8,
  },
  tab: {
    paddingHorizontal: 14,
    paddingVertical: 10,
    marginRight: 4,
  },
  activeTab: {
    borderBottomWidth: 2,
    borderBottomColor: '#2563eb',
  },
  tabText: {
    fontSize: 13,
    color: '#64748b',
    fontWeight: '600',
  },
  activeTabText: {
    color: '#2563eb',
    fontWeight: 'bold',
  },
  subTabsContainer: {
    flexDirection: 'row',
    backgroundColor: '#f1f5f9',
    padding: 4,
    marginHorizontal: 12,
    marginTop: 8,
    borderRadius: 8,
  },
  subTab: {
    flex: 1,
    paddingVertical: 8,
    alignItems: 'center',
    borderRadius: 6,
  },
  activeSubTab: {
    backgroundColor: '#ffffff',
    shadowColor: '#000',
    shadowOpacity: 0.05,
    shadowRadius: 4,
    elevation: 1,
  },
  subTabText: {
    fontSize: 12,
    fontWeight: '600',
    color: '#64748b',
  },
  activeSubTabText: {
    color: '#2563eb',
    fontWeight: 'bold',
  },
  searchBarContainer: {
    flexDirection: 'row',
    paddingHorizontal: 12,
    paddingTop: 10,
    gap: 8,
  },
  searchInput: {
    flex: 1,
    backgroundColor: '#ffffff',
    borderWidth: 1,
    borderColor: '#cbd5e1',
    borderRadius: 8,
    paddingHorizontal: 12,
    paddingVertical: 8,
    fontSize: 14,
  },
  filterBtn: {
    backgroundColor: '#e2e8f0',
    paddingHorizontal: 12,
    justifyContent: 'center',
    borderRadius: 8,
  },
  filterBtnText: {
    fontSize: 13,
    fontWeight: 'bold',
    color: '#334155',
  },
  chipsContainer: {
    flexDirection: 'row',
    paddingHorizontal: 12,
    paddingTop: 6,
    gap: 6,
  },
  chip: {
    backgroundColor: '#eff6ff',
    borderColor: '#93c5fd',
    borderWidth: 1,
    borderRadius: 14,
    paddingHorizontal: 10,
    paddingVertical: 4,
  },
  chipText: {
    color: '#2563eb',
    fontSize: 11,
    fontWeight: '600',
  },
  content: {
    padding: 12,
  },
  cardContainer: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'space-between',
  },
  card: {
    width: '48%',
    padding: 16,
    borderRadius: 12,
    marginBottom: 12,
  },
  cardLabel: {
    color: 'rgba(255,255,255,0.85)',
    fontSize: 11,
    fontWeight: '700',
    textTransform: 'uppercase',
  },
  cardValue: {
    color: '#ffffff',
    fontSize: 28,
    fontWeight: 'bold',
    marginTop: 4,
  },
  listContainer: {
    paddingBottom: 30,
  },
  sectionHeader: {
    fontSize: 12,
    fontWeight: 'bold',
    color: '#64748b',
    marginBottom: 10,
    letterSpacing: 0.5,
  },
  emptyState: {
    backgroundColor: '#ffffff',
    padding: 30,
    borderRadius: 12,
    alignItems: 'center',
  },
  emptyStateText: {
    color: '#94a3b8',
    fontSize: 14,
  },
  itemCard: {
    backgroundColor: '#ffffff',
    padding: 14,
    borderRadius: 10,
    marginBottom: 10,
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  itemHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: 6,
  },
  itemPartNo: {
    fontSize: 15,
    fontWeight: 'bold',
    color: '#0f172a',
  },
  itemStatus: {
    fontSize: 11,
    fontWeight: 'bold',
    color: '#2563eb',
    backgroundColor: '#eff6ff',
    paddingHorizontal: 8,
    paddingVertical: 2,
    borderRadius: 4,
  },
  itemSubText: {
    fontSize: 13,
    color: '#64748b',
    marginTop: 2,
  },
  actionBtn: {
    padding: 10,
    borderRadius: 6,
    alignItems: 'center',
  },
  actionBtnText: {
    color: '#ffffff',
    fontWeight: 'bold',
    fontSize: 12,
  },
  revertBtn: {
    backgroundColor: '#fef2f2',
    borderColor: '#fca5a5',
    borderWidth: 1,
    padding: 8,
    borderRadius: 6,
    marginTop: 8,
    alignItems: 'center',
  },
  revertBtnText: {
    color: '#ef4444',
    fontWeight: 'bold',
    fontSize: 12,
  },
  statsRow: {
    marginTop: 6,
    gap: 8,
  },
  sideCardBox: {
    backgroundColor: '#f8fafc',
    padding: 8,
    borderRadius: 6,
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  statBadge: {
    color: '#334155',
    fontSize: 12,
    fontWeight: '600',
  },
  smallReceiveBtn: {
    backgroundColor: '#2563eb',
    padding: 6,
    borderRadius: 4,
    marginTop: 6,
    alignItems: 'center',
  },
  smallReceiveBtnText: {
    color: '#ffffff',
    fontWeight: 'bold',
    fontSize: 11,
  },
  swipeLegendText: {
    textAlign: 'center',
    fontSize: 11,
    color: '#94a3b8',
    fontWeight: '600',
  },
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.5)',
    justifyContent: 'center',
    padding: 20,
  },
  modalBox: {
    backgroundColor: '#ffffff',
    borderRadius: 12,
    padding: 20,
  },
  modalTitle: {
    fontSize: 18,
    fontWeight: 'bold',
    color: '#0f172a',
    marginBottom: 12,
  },
  chipBtn: {
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 6,
    backgroundColor: '#f1f5f9',
  },
  chipBtnActive: {
    backgroundColor: '#2563eb',
  },
  chipBtnText: {
    fontSize: 12,
    fontWeight: 'bold',
    color: '#475569',
  },
  chipBtnTextActive: {
    color: '#ffffff',
  },
});
