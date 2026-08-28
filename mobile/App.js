import React, { useState, useEffect, useRef, useCallback, useMemo } from 'react';
import {
  StyleSheet,
  Text,
  View,
  Image,
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
  Platform,
} from 'react-native';
import { StatusBar } from 'expo-status-bar';
import { registerRootComponent } from 'expo';
import * as Updates from 'expo-updates';
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

function CompactQuantitySelector({
  available,
  max,
  value = '1',
  onChange,
  color = '#2563eb',
  label = 'Quantity',
  remainingLabel = 'Remaining in Queue',
}) {
  const num = parseInt(value, 10) || 1;
  const maxAvail = Math.max(1, parseInt(available !== undefined ? available : (max !== undefined ? max : 1), 10) || 1);
  const remaining = Math.max(0, maxAvail - num);

  return (
    <View style={compactQtyStyles.container}>
      <View style={compactQtyStyles.metaRow}>
        <Text style={[compactQtyStyles.label, { color }]}>
          {label} <Text style={compactQtyStyles.maxBadge}>(Max: {maxAvail})</Text>
        </Text>
        <Text style={compactQtyStyles.remainingText}>
          {remainingLabel}: <Text style={compactQtyStyles.remainingValue}>{remaining} pcs</Text>
        </Text>
      </View>

      <View style={compactQtyStyles.stepperRow}>
        <TouchableOpacity
          activeOpacity={0.7}
          style={[compactQtyStyles.stepBtn, { borderColor: color }]}
          onPress={() => onChange(String(Math.max(1, num - 1)))}>
          <Text style={[compactQtyStyles.stepBtnText, { color }]}>−</Text>
        </TouchableOpacity>

        <TextInput
          style={[compactQtyStyles.input, { borderColor: color }]}
          keyboardType="numeric"
          value={String(value)}
          onChangeText={(txt) => {
            const clean = txt.replace(/[^0-9]/g, '');
            if (clean === '') {
              onChange('');
              return;
            }
            const val = parseInt(clean, 10);
            onChange(String(Math.min(maxAvail, Math.max(1, val))));
          }}
        />

        <TouchableOpacity
          activeOpacity={0.7}
          style={[compactQtyStyles.stepBtn, { borderColor: color }]}
          onPress={() => onChange(String(Math.min(maxAvail, num + 1)))}>
          <Text style={[compactQtyStyles.stepBtnText, { color }]}>+</Text>
        </TouchableOpacity>
      </View>
    </View>
  );
}

const compactQtyStyles = StyleSheet.create({
  container: {
    backgroundColor: '#f8fafc',
    borderRadius: 8,
    borderWidth: 1,
    borderColor: '#e2e8f0',
    padding: 10,
    marginTop: 8,
    marginBottom: 8,
  },
  metaRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 8,
  },
  label: {
    fontSize: 13,
    fontWeight: '800',
  },
  maxBadge: {
    fontSize: 12,
    fontWeight: '600',
    color: '#64748b',
  },
  remainingText: {
    fontSize: 12,
    color: '#64748b',
  },
  remainingValue: {
    fontWeight: '800',
    color: '#0f172a',
  },
  stepperRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 10,
  },
  stepBtn: {
    width: 44,
    height: 40,
    backgroundColor: '#ffffff',
    borderWidth: 1.5,
    borderRadius: 8,
    alignItems: 'center',
    justifyContent: 'center',
  },
  stepBtnText: {
    fontSize: 20,
    fontWeight: '900',
    lineHeight: 22,
  },
  input: {
    flex: 1,
    maxWidth: 120,
    height: 40,
    backgroundColor: '#ffffff',
    borderWidth: 1.5,
    borderRadius: 8,
    textAlign: 'center',
    fontSize: 16,
    fontWeight: '800',
    color: '#0f172a',
  },
});

function CompactInlineRevertRow({ item, onRevert, disabled }) {
  const maxAvail = item.available_quantity || 1;
  const [qty, setQty] = useState(String(maxAvail));

  useEffect(() => {
    setQty(String(item.available_quantity || 1));
  }, [item.available_quantity]);

  const numQty = parseInt(qty, 10) || 0;
  const isValid = numQty >= 1 && numQty <= maxAvail;

  const decrement = () => {
    const next = Math.max(1, (parseInt(qty, 10) || 1) - 1);
    setQty(String(next));
  };

  const increment = () => {
    const next = Math.min(maxAvail, (parseInt(qty, 10) || 0) + 1);
    setQty(String(next));
  };

  return (
    <View style={compactInlineRevertStyles.row}>
      <Text style={compactInlineRevertStyles.availText}>
        Avail: <Text style={{ fontWeight: '800', color: '#1e293b' }}>{maxAvail}</Text>
      </Text>

      <View style={compactInlineRevertStyles.stepperBox}>
        <TouchableOpacity
          style={[compactInlineRevertStyles.stepBtn, numQty <= 1 && { opacity: 0.4 }]}
          disabled={numQty <= 1 || disabled}
          onPress={decrement}>
          <Text style={compactInlineRevertStyles.stepBtnText}>−</Text>
        </TouchableOpacity>

        <TextInput
          style={compactInlineRevertStyles.input}
          keyboardType="numeric"
          value={qty}
          onChangeText={(val) => {
            const clean = val.replace(/[^0-9]/g, '');
            setQty(clean);
          }}
        />

        <TouchableOpacity
          style={[compactInlineRevertStyles.stepBtn, numQty >= maxAvail && { opacity: 0.4 }]}
          disabled={numQty >= maxAvail || disabled}
          onPress={increment}>
          <Text style={compactInlineRevertStyles.stepBtnText}>+</Text>
        </TouchableOpacity>
      </View>

      <TouchableOpacity
        style={[
          compactInlineRevertStyles.actionBtn,
          (!isValid || disabled) && { opacity: 0.4 }
        ]}
        disabled={!isValid || disabled}
        onPress={() => onRevert(item, numQty)}>
        <Text style={compactInlineRevertStyles.actionBtnText}>
          ↩ Revert ({isValid ? numQty : '−'})
        </Text>
      </TouchableOpacity>
    </View>
  );
}

const compactInlineRevertStyles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginTop: 6,
    paddingTop: 6,
    borderTopWidth: 1,
    borderTopColor: '#f1f5f9',
    gap: 6,
  },
  availText: {
    fontSize: 11,
    color: '#64748b',
    fontWeight: '600',
  },
  stepperBox: {
    flexDirection: 'row',
    alignItems: 'center',
    backgroundColor: '#ffffff',
    borderRadius: 6,
    borderWidth: 1,
    borderColor: '#cbd5e1',
    height: 32,
    overflow: 'hidden',
  },
  stepBtn: {
    width: 28,
    height: 32,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#f1f5f9',
  },
  stepBtnText: {
    fontSize: 16,
    fontWeight: '800',
    color: '#334155',
    lineHeight: 18,
  },
  input: {
    width: 38,
    height: 32,
    textAlign: 'center',
    fontSize: 13,
    fontWeight: '800',
    color: '#0f172a',
    padding: 0,
    backgroundColor: '#ffffff',
  },
  actionBtn: {
    backgroundColor: '#dc2626',
    paddingHorizontal: 10,
    height: 32,
    borderRadius: 6,
    justifyContent: 'center',
    alignItems: 'center',
  },
  actionBtnText: {
    color: '#ffffff',
    fontSize: 11,
    fontWeight: '800',
  },
});

function App() {
  const [token, setToken] = useState(null);
  const [user, setUser] = useState(null);
  const [userRole, setUserRole] = useState('');
  const [serverHost, setServerHost] = useState(
    process.env.EXPO_PUBLIC_API_URL 
      ? process.env.EXPO_PUBLIC_API_URL.replace(/^https?:\/\//i, '').replace(/\/api\/v1\/?$/i, '')
      : '192.168.9.200:8080'
  );
  const [email, setEmail] = useState('admin@sparetrack.internal');
  const [password, setPassword] = useState('password123');

  const [activeTab, setActiveTab] = useState('dashboard');
  const [storeSubTab, setStoreSubTab] = useState('pending'); // 'pending' | 'revert'
  const [qcSubTab, setQcSubTab] = useState('arrival'); // 'arrival' | 'inspection' | 'revert'
  const [reworkSubTab, setReworkSubTab] = useState('queue'); // 'queue' | 'revert'
  const [paintSubTab, setPaintSubTab] = useState('queue'); // 'queue' | 'revert'
  const [assemblySubTab, setAssemblySubTab] = useState('queue'); // 'queue' | 'completed' | 'revert'
  const [summary, setSummary] = useState(null);
  const [items, setItems] = useState([]);
  const [projects, setProjects] = useState([]);
  const [loading, setLoading] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');

  // Search & Filter state - Per-Tab Isolated Search State (Part 13)
  const [tabSearches, setTabSearches] = useState({
    store_pending: '',
    store_revert: '',
    qc_arrival: '',
    qc_inspection: '',
    qc_revert: '',
    rework_queue: '',
    rework_revert: '',
    paint_queue: '',
    paint_revert: '',
    assembly_queue: '',
    assembly_completed: '',
    assembly_revert: '',
    purchase: '',
  });

  const [paintStatusFilter, setPaintStatusFilter] = useState('all'); // 'all' | 'active' | 'completed'
  const [selectedSide, setSelectedSide] = useState('');
  const [selectedProject, setSelectedProject] = useState('');
  const [showFilterModal, setShowFilterModal] = useState(false);
  const searchTimer = useRef(null);
  const mainScrollRef = useRef(null);

  const scrollToTop = useCallback((animated = false) => {
    if (mainScrollRef.current) {
      mainScrollRef.current.scrollTo({ y: 0, animated });
    }
  }, []);

  const getCurrentSearchKey = (
    tab = activeTab,
    storeSub = storeSubTab,
    qcSub = qcSubTab,
    reworkSub = reworkSubTab,
    paintSub = paintSubTab,
    assemblySub = assemblySubTab
  ) => {
    if (tab === 'store') return storeSub === 'revert' ? 'store_revert' : 'store_pending';
    if (tab === 'qc') {
      if (qcSub === 'arrival') return 'qc_arrival';
      if (qcSub === 'inspection') return 'qc_inspection';
      return 'qc_revert';
    }
    if (tab === 'rework') return reworkSub === 'revert' ? 'rework_revert' : 'rework_queue';
    if (tab === 'paint') return paintSub === 'revert' ? 'paint_revert' : 'paint_queue';
    if (tab === 'assembly') {
      if (assemblySub === 'completed') return 'assembly_completed';
      if (assemblySub === 'revert') return 'assembly_revert';
      return 'assembly_queue';
    }
    return tab;
  };

  const getCurrentSubTabForDept = (dept = activeTab) => {
    if (dept === 'store') return storeSubTab;
    if (dept === 'qc') return qcSubTab;
    if (dept === 'rework') return reworkSubTab;
    if (dept === 'paint') return paintSubTab;
    if (dept === 'assembly') return assemblySubTab;
    return 'main';
  };

  const activeTabRef = useRef(activeTab);
  useEffect(() => {
    activeTabRef.current = activeTab;
  }, [activeTab]);

  const currentRequestIdRef = useRef(0);
  const currentGlobalRevertReqIdRef = useRef(0);

  const currentSearchQuery = tabSearches[getCurrentSearchKey()] || '';

  // Store Receive Modal state
  const [showReceiveModal, setShowReceiveModal] = useState(false);
  const [selectedItemForReceive, setSelectedItemForReceive] = useState(null);
  const [receiveSide, setReceiveSide] = useState('RH');
  const [receiveQty, setReceiveQty] = useState('1');
  const [deliveryNote, setDeliveryNote] = useState('');
  const [isSubmittingReceive, setIsSubmittingReceive] = useState(false); // Idempotency: prevent duplicate receipt submissions

  // Rework Completion Modal state
  const [showReworkModal, setShowReworkModal] = useState(false);
  const [selectedReworkItem, setSelectedReworkItem] = useState(null);
  const [reworkQty, setReworkQty] = useState('1');
  const [reworkNotes, setReworkNotes] = useState('');

  // QC Physical Arrival Modal state
  const [showPhysicalArrivalModal, setShowPhysicalArrivalModal] = useState(false);
  const [selectedPhysicalArrivalItem, setSelectedPhysicalArrivalItem] = useState(null);
  const [physicalArrivalQty, setPhysicalArrivalQty] = useState('1');

  // QC Inspection Modal state
  const [showQcModal, setShowQcModal] = useState(false);
  const [selectedQcItem, setSelectedQcItem] = useState(null);
  const [qcResult, setQcResult] = useState('approved'); // 'approved' | 'rejected' | 'rework' | 'partial'
  const [qcDestination, setQcDestination] = useState(''); // 'PAINT' | 'ASSEMBLY'
  const [qcApprovedQty, setQcApprovedQty] = useState('1');
  const [qcPaintQty, setQcPaintQty] = useState('1');
  const [qcAssemblyQty, setQcAssemblyQty] = useState('0');
  const [qcRejectedQty, setQcRejectedQty] = useState('0');
  const [qcReworkQty, setQcReworkQty] = useState('0');
  const [qcReason, setQcReason] = useState('');
  const [qcRemarks, setQcRemarks] = useState('');

  // Paint Modal state
  const [showPaintModal, setShowPaintModal] = useState(false);
  const [selectedPaintItem, setSelectedPaintItem] = useState(null);
  const [paintQty, setPaintQty] = useState('1');
  const [paintType, setPaintType] = useState('RAL 7035 Powder Coat');
  const [paintRemarks, setPaintRemarks] = useState('');

  // Assembly Modal state
  const [showAssemblyModal, setShowAssemblyModal] = useState(false);
  const [selectedAssemblyItem, setSelectedAssemblyItem] = useState(null);
  const [assemblyQty, setAssemblyQty] = useState('1');
  const [assemblyRemarks, setAssemblyRemarks] = useState('');
  const [isSubmittingAssembly, setIsSubmittingAssembly] = useState(false);

  // Universal Strict Lineage Revert Modal State
  const [showRevertModal, setShowRevertModal] = useState(false);
  const [revertTargetItem, setRevertTargetItem] = useState(null);
  const [revertDept, setRevertDept] = useState('');
  const [revertSide, setRevertSide] = useState('LH');
  const [revertOptionsList, setRevertOptionsList] = useState([]);
  const [selectedRevertOption, setSelectedRevertOption] = useState(null);
  const [revertQty, setRevertQty] = useState('1');
  const [revertReason, setRevertReason] = useState('');
  const [isSubmittingRevert, setIsSubmittingRevert] = useState(false);

  // Multi-Selection & Bulk Operations State (Issue 5)
  const [isSelectionMode, setIsSelectionMode] = useState(false);
  const [selectedItemIds, setSelectedItemIds] = useState(new Set());
  const [showBulkStoreReceiveModal, setShowBulkStoreReceiveModal] = useState(false);
  const [bulkDeliveryNote, setBulkDeliveryNote] = useState('');
  const [showBulkQcDestinationModal, setShowBulkQcDestinationModal] = useState(false);
  const [showBulkPaintModal, setShowBulkPaintModal] = useState(false);
  const [showBulkReworkModal, setShowBulkReworkModal] = useState(false);
  const [bulkPaintType, setBulkPaintType] = useState('RAL 7035 Powder Coat');
  const [bulkReworkNotes, setBulkReworkNotes] = useState('');

  // Non-blocking Toast notification state (Issue 4)
  const [toast, setToast] = useState({ visible: false, message: '', type: 'success' });
  const showToast = (message, type = 'success') => {
    setToast({ visible: true, message, type });
    setTimeout(() => {
      setToast(prev => ({ ...prev, visible: false }));
    }, 2800);
  };

  // Mobile Store Hierarchy State
  const [hierarchyJigs, setHierarchyJigs] = useState([]);
  const [hierarchyProject, setHierarchyProject] = useState(null);
  const [selectedJig, setSelectedJig] = useState(null);
  const [selectedUnit, setSelectedUnit] = useState(null);
  const [unitSideTab, setUnitSideTab] = useState('LH'); // 'LH' | 'RH'

  // Department-Namespaced Global / Upper Revert State (Isolates Store, QC, Rework, Paint, Assembly)
  const [deptGlobalRevertItems, setDeptGlobalRevertItems] = useState({
    store: [],
    qc: [],
    rework: [],
    paint: [],
    assembly: [],
  });

  // Derived strictly department-isolated revert items for the active department
  const globalRevertItems = useMemo(() => {
    const rawList = deptGlobalRevertItems[activeTab] || [];
    return rawList.filter(item => (item.from_department || '').toLowerCase() === activeTab.toLowerCase());
  }, [deptGlobalRevertItems, activeTab]);

  const [isLoadingGlobalRevert, setIsLoadingGlobalRevert] = useState(false);
  const [selectedGlobalRevertIds, setSelectedGlobalRevertIds] = useState(new Set());
  const [isGlobalRevertSelectionMode, setIsGlobalRevertSelectionMode] = useState(false);
  const [showBulkGlobalRevertModal, setShowBulkGlobalRevertModal] = useState(false);
  const [bulkGlobalRevertReason, setBulkGlobalRevertReason] = useState('');
  const [isSubmittingGlobalRevert, setIsSubmittingGlobalRevert] = useState(false);

  // In-Memory Tab Data Cache for Instant Zero-Delay Tab Switching
  const mobileCacheRef = useRef(new Map());
  const invalidateMobileCache = (prefix = '') => {
    if (!prefix) {
      mobileCacheRef.current.clear();
      return;
    }
    for (const key of Array.from(mobileCacheRef.current.keys())) {
      if (key.includes(prefix) || key.startsWith(prefix)) {
        mobileCacheRef.current.delete(key);
      }
    }
  };

  const getItemSelectionKey = (item, side = unitSideTab) => `${item.id}_${side}`;

  const toggleSelection = (item, side = unitSideTab) => {
    const key = getItemSelectionKey(item, side);
    setSelectedItemIds(prev => {
      const next = new Set(prev);
      if (next.has(key)) {
        next.delete(key);
      } else {
        next.add(key);
      }
      if (next.size === 0) setIsSelectionMode(false);
      else setIsSelectionMode(true);
      return next;
    });
  };

  const selectAllVisible = (visibleItems, side = unitSideTab) => {
    const allKeys = visibleItems.map(i => getItemSelectionKey(i, side));
    setSelectedItemIds(new Set(allKeys));
    setIsSelectionMode(allKeys.length > 0);
  };

  const clearSelection = () => {
    setSelectedItemIds(new Set());
    setIsSelectionMode(false);
  };

  const getSearchPlaceholder = () => {
    if (activeTab === 'store') {
      if (storeSubTab === 'revert') return '🔍 Search revertible parts in Store...';
      if (!selectedProject) return '🔍 Search projects by name / code...';
      if (!selectedJig) return '🔍 Search JIGs (e.g. ST7)...';
      if (!selectedUnit) return '🔍 Search units (e.g. 07, Unit 07)...';
      return '🔍 Search pending parts in this unit...';
    }
    if (activeTab === 'qc') {
      if (qcSubTab === 'arrival') return '🔍 Search arrival queue...';
      if (qcSubTab === 'inspection') return '🔍 Search inspection queue...';
      return '🔍 Search revertible parts in QC...';
    }
    if (activeTab === 'paint') return paintSubTab === 'revert' ? '🔍 Search revertible parts in Paint...' : '🔍 Search Paint queue...';
    if (activeTab === 'assembly') return assemblySubTab === 'revert' ? '🔍 Search revertible parts in Assembly...' : '🔍 Search Assembly queue...';
    if (activeTab === 'rework') return reworkSubTab === 'revert' ? '🔍 Search revertible parts in Rework...' : '🔍 Search Rework items...';
    if (activeTab === 'purchase') return '🔍 Search Purchase queue...';
    return '🔍 Search items...';
  };

  // Sync baseUrl with serverHost state
  useEffect(() => {
    if (serverHost) {
      setBaseUrl(serverHost);
    }
  }, [serverHost]);

  // Reactive Context Transition Watcher: Reset scroll to top and clear selection when navigating screens
  const screenContextKey = `${activeTab}_${storeSubTab}_${qcSubTab}_${paintStatusFilter}_${selectedProject || ''}_${selectedJig?.id || selectedJig?.jig_name || ''}_${selectedUnit?.unit_no || ''}_${unitSideTab}`;

  useEffect(() => {
    scrollToTop(false);
    clearSelection();
  }, [screenContextKey, scrollToTop]);

  // 30s Polling Loop for live real-time updates
  useEffect(() => {
    if (!token) return;
    const interval = setInterval(() => {
      loadData(activeTab, false);
    }, 30000);
    return () => clearInterval(interval);
  }, [token, activeTab, storeSubTab, tabSearches, selectedSide, selectedProject]);

  const isTopLevelRevertTab = !selectedUnit && (
    (activeTab === 'store' && storeSubTab === 'revert') ||
    (activeTab === 'qc' && qcSubTab === 'revert') ||
    (activeTab === 'rework' && reworkSubTab === 'revert') ||
    (activeTab === 'paint' && paintSubTab === 'revert') ||
    (activeTab === 'assembly' && assemblySubTab === 'revert')
  );

  const loadGlobalRevertItems = useCallback(async (dept = activeTab, showSpinner = true) => {
    if (!token) return;
    const thisGlobalReqId = ++currentGlobalRevertReqIdRef.current;
    if (showSpinner) setIsLoadingGlobalRevert(true);
    try {
      const activeSearch = tabSearches[getCurrentSearchKey(dept, storeSubTab, qcSubTab, reworkSubTab, paintSubTab, assemblySubTab)] || '';
      const params = {
        department: dept,
        per_page: 200,
      };
      if (selectedProject) params.project_id = selectedProject;
      if (selectedSide) params.side = selectedSide;
      if (activeSearch) params.search = activeSearch;

      const res = await apiClient.get('/workflow/revert-items', { params });
      if (thisGlobalReqId === currentGlobalRevertReqIdRef.current && activeTabRef.current === dept) {
        const receivedItems = Array.isArray(res.data?.items) ? res.data.items : [];
        const validatedItems = receivedItems.filter(i => (i.from_department || '').toLowerCase() === dept.toLowerCase());
        setDeptGlobalRevertItems(prev => ({
          ...prev,
          [dept]: validatedItems
        }));
      }
    } catch (err) {
      console.log('Error loading global revert items:', err);
    } finally {
      if (thisGlobalReqId === currentGlobalRevertReqIdRef.current) {
        if (showSpinner) setIsLoadingGlobalRevert(false);
      }
    }
  }, [token, activeTab, storeSubTab, qcSubTab, reworkSubTab, paintSubTab, assemblySubTab, tabSearches, selectedProject, selectedSide]);

  useEffect(() => {
    if (isTopLevelRevertTab && token) {
      loadGlobalRevertItems(activeTab, true);
    }
  }, [isTopLevelRevertTab, activeTab, storeSubTab, qcSubTab, reworkSubTab, paintSubTab, assemblySubTab, selectedProject, selectedSide, tabSearches, loadGlobalRevertItems]);

  const [otaChecking, setOtaChecking] = useState(false);

  const handleCheckOtaUpdate = async () => {
    setOtaChecking(true);
    try {
      if (__DEV__) {
        Alert.alert('Development Build', 'Running in local Expo development mode. OTA updates apply to preview/production builds.');
        return;
      }
      const update = await Updates.checkForUpdateAsync();
      if (update.isAvailable) {
        showToast('Downloading update...');
        await Updates.fetchUpdateAsync();
        Alert.alert(
          'Update Downloaded',
          'A new update has been downloaded. Would you like to restart the app to apply it now?',
          [
            { text: 'Later', style: 'cancel' },
            { text: 'Restart Now', onPress: () => Updates.reloadAsync() }
          ]
        );
      } else {
        Alert.alert('Up to Date', 'You are already running the latest version of SpareTrack.');
      }
    } catch (err) {
      Alert.alert('Update Check Failed', err.message || 'Could not check for OTA updates.');
    } finally {
      setOtaChecking(false);
    }
  };

  // Automatic OTA update check on app launch
  useEffect(() => {
    async function autoCheckOta() {
      if (__DEV__) return;
      try {
        const update = await Updates.checkForUpdateAsync();
        if (update.isAvailable) {
          showToast('Downloading latest update...');
          await Updates.fetchUpdateAsync();
          Alert.alert(
            'New Update Available',
            'A new update with Store Revert and JIG Units fixes has been downloaded. Restart the app now to apply it immediately?',
            [
              { text: 'Later', style: 'cancel' },
              { text: 'Restart Now', onPress: () => Updates.reloadAsync() }
            ]
          );
        }
      } catch (e) {
        console.log('OTA Auto-check error:', e);
      }
    }
    autoCheckOta();
  }, []);

  const [testingConnection, setTestingConnection] = useState(false);
  const [connectionStatus, setConnectionStatus] = useState(null);

  const handleTestConnection = async (hostOverride = null) => {
    const targetHost = hostOverride || serverHost;
    setTestingConnection(true);
    setConnectionStatus(null);
    try {
      if (targetHost) {
        setBaseUrl(targetHost);
      }
      const startTime = Date.now();
      const res = await apiClient.get('/health', { timeout: 5000 });
      const elapsed = Date.now() - startTime;
      setConnectionStatus({
        success: true,
        msg: `✓ Connected to Faith Automation API (${elapsed}ms)`,
      });
      showToast(`✓ Server connected (${elapsed}ms)`);
    } catch (err) {
      const targetUrl = `${apiClient.defaults.baseURL || getBaseUrl()}/health`;
      setConnectionStatus({
        success: false,
        msg: `❌ Cannot reach ${targetUrl}\n${err.message || 'Connection timeout'}. Please verify phone is on same Wi-Fi and Mobile Data is OFF.`,
      });
    } finally {
      setTestingConnection(false);
    }
  };

  const handleLogin = async () => {
    if (!email || !password) {
      Alert.alert('Validation Error', 'Please enter email and password.');
      return;
    }

    setLoading(true);
    setErrorMsg('');
    try {
      if (serverHost) {
        let cleanHost = serverHost.trim();
        cleanHost = cleanHost.replace(/^https?:\/\//i, '');
        cleanHost = cleanHost.replace(/\/api\/v1\/?$/i, '');
        cleanHost = cleanHost.replace(/\/+$/, '');
        const newBase = `http://${cleanHost}/api/v1`;
        apiClient.defaults.baseURL = newBase;
      }

      const res = await apiClient.post('/auth/login', { email, password });
      const { token: receivedToken, user: receivedUser } = res.data;

      setToken(receivedToken);
      setUser(receivedUser);
      setAuthToken(receivedToken);

      const role = receivedUser.role?.name || (receivedUser.roles && receivedUser.roles[0]?.name) || '';
      setUserRole(role);

      let initialTab = 'dashboard';
      if (role === 'STORE') initialTab = 'store';
      else if (role === 'QC') initialTab = 'qc';
      else if (role === 'REWORK') initialTab = 'rework';
      else if (role === 'PAINT') initialTab = 'paint';
      else if (role === 'ASSEMBLY') initialTab = 'assembly';
      else if (role === 'PURCHASE') initialTab = 'purchase';

      setActiveTab(initialTab);
      mobileCacheRef.current.clear();
      await loadData(initialTab);
    } catch (err) {
      const msg = err.response?.data?.message || err.message || 'Could not connect to server.';
      const targetUrl = `${apiClient.defaults.baseURL || getBaseUrl()}/auth/login`;
      setErrorMsg(`Connection Error: ${msg}\n\nTarget Endpoint: ${targetUrl}\n\nPlease ensure phone is on the same Wi-Fi and Mobile Data is turned OFF.`);
      Alert.alert('Login Failed', `${msg}\n\nTarget: ${targetUrl}`);
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
          setProjects([]);
          setHierarchyJigs([]);
          setHierarchyProject(null);
          setSelectedJig(null);
          setSelectedUnit(null);
          setSelectedProject('');
          setSelectedSide('');
          setDeptGlobalRevertItems({
            store: [],
            qc: [],
            rework: [],
            paint: [],
            assembly: [],
          });
          setSelectedGlobalRevertIds(new Set());
          setIsGlobalRevertSelectionMode(false);
          mobileCacheRef.current.clear();
          setTabSearches({
            store_pending: '',
            store_revert: '',
            qc_arrival: '',
            qc_inspection: '',
            qc_revert: '',
            rework_queue: '',
            rework_revert: '',
            paint_queue: '',
            paint_revert: '',
            assembly_queue: '',
            assembly_completed: '',
            assembly_revert: '',
            purchase: '',
          });
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

  const loadData = async (tab = activeTab, showSpinner = true, customSearch = null, forceFresh = false) => {
    const subTab = getCurrentSubTabForDept(tab);
    const activeSearch = customSearch !== null ? customSearch : (tabSearches[getCurrentSearchKey(tab, storeSubTab, qcSubTab, reworkSubTab, paintSubTab, assemblySubTab)] || '');
    const cacheKey = `${user?.id || 'anon'}_${userRole}_${tab}_${subTab}_${selectedProject || ''}_${selectedSide || ''}_${activeSearch}`;

    const thisRequestId = ++currentRequestIdRef.current;

    // 1. Check in-memory cache for instant zero-delay render
    const cachedEntry = mobileCacheRef.current.get(cacheKey);
    if (cachedEntry && !forceFresh) {
      if (tab === activeTabRef.current) {
        if (tab === 'dashboard') {
          setSummary(cachedEntry);
        } else if (tab === 'purchase') {
          setItems(cachedEntry);
        } else {
          if (cachedEntry.projects) setProjects(cachedEntry.projects);
          if (cachedEntry.is_hierarchical) {
            setHierarchyJigs(cachedEntry.jigs || []);
            setHierarchyProject(cachedEntry.project || null);
          }
        }
      }
      if (showSpinner) setLoading(false);
    } else if (showSpinner) {
      setLoading(true);
    }

    try {
      const params = { per_page: 100 };
      if (activeSearch) params.search = activeSearch;
      if (selectedSide) params.side = selectedSide;
      if (selectedProject) params.project_id = selectedProject;

      if (tab === 'dashboard') {
        const res = await apiClient.get('/dashboard/summary', { params });
        const data = res.data.summary || res.data;
        mobileCacheRef.current.set(cacheKey, data);
        if (thisRequestId === currentRequestIdRef.current && activeTabRef.current === 'dashboard') {
          setSummary(data);
        }
      } else if (tab === 'purchase') {
        const res = await apiClient.get('/purchase/items', { params });
        const data = extractArray(res.data);
        mobileCacheRef.current.set(cacheKey, data);
        if (thisRequestId === currentRequestIdRef.current && activeTabRef.current === 'purchase') {
          setItems(data);
        }
      } else {
        // Operational department hierarchy: store, qc, rework, paint, assembly
        const hierarchyEndpoint = `/${tab}/hierarchy`;
        const res = await apiClient.get(hierarchyEndpoint, { params: { project_id: selectedProject, side: selectedSide, search: activeSearch } });
        mobileCacheRef.current.set(cacheKey, res.data);

        // Guard against stale responses from previous tabs or rapid switches
        if (thisRequestId === currentRequestIdRef.current && activeTabRef.current === tab) {
          if (res.data.projects) setProjects(res.data.projects);
          if (res.data.is_hierarchical) {
            const updatedJigs = res.data.jigs || [];
            setHierarchyJigs(updatedJigs);
            setHierarchyProject(res.data.project || null);

            // Sync selectedJig and selectedUnit references with newly fetched data
            if (selectedJig) {
              const newJig = updatedJigs.find(j => j.jig_name === selectedJig.jig_name);
              if (newJig) {
                setSelectedJig(newJig);
                if (selectedUnit) {
                  const newUnit = newJig.units?.find(u => u.unit_no === selectedUnit.unit_no);
                  if (newUnit) {
                    setSelectedUnit(newUnit);
                  }
                }
              }
            }
          } else if (!selectedProject) {
            setHierarchyJigs([]);
            setHierarchyProject(null);
          }
        }
      }
    } catch (err) {
      console.log(`Error loading ${tab} data:`, err);
    } finally {
      if (thisRequestId === currentRequestIdRef.current) {
        if (showSpinner) setLoading(false);
        setRefreshing(false);
      }
    }
  };

  const handleSearchChange = (text) => {
    const key = getCurrentSearchKey();
    setTabSearches(prev => ({ ...prev, [key]: text }));
    clearTimeout(searchTimer.current);
    searchTimer.current = setTimeout(() => {
      loadData(activeTab, true, text);
    }, 300);
  };

  const handleClearSearch = () => {
    const key = getCurrentSearchKey();
    setTabSearches(prev => ({ ...prev, [key]: '' }));
    loadData(activeTab, false, '');
  };

  const onRefresh = () => {
    setRefreshing(true);
    loadData(activeTab);
  };

  const handleTabChange = (tab) => {
    setActiveTab(tab);
    loadData(tab);
  };

  const handleSelectProject = async (projId) => {
    setSelectedProject(projId);
    setSelectedJig(null);
    setSelectedUnit(null);
    const thisReqId = ++currentRequestIdRef.current;
    setLoading(true);
    try {
      const hierarchyEndpoint = `/${activeTab === 'dashboard' ? 'store' : activeTab}/hierarchy`;
      const res = await apiClient.get(hierarchyEndpoint, { params: { project_id: projId, side: selectedSide } });
      if (thisReqId === currentRequestIdRef.current) {
        if (res.data.is_hierarchical) {
          setHierarchyJigs(res.data.jigs || []);
          setHierarchyProject(res.data.project || null);
        } else {
          setHierarchyJigs([]);
          setHierarchyProject(null);
        }
      }
    } catch (err) {
      console.log("Error selecting project:", err);
    } finally {
      if (thisReqId === currentRequestIdRef.current) {
        setLoading(false);
      }
    }
  };

  const handleResetProject = () => {
    setSelectedProject('');
    setSelectedJig(null);
    setSelectedUnit(null);
    setHierarchyJigs([]);
    setHierarchyProject(null);
    loadData(activeTab);
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
    if (isSubmittingReceive) return;  // Idempotency guard: prevent double-tap
    const qty = parseInt(receiveQty, 10);
    if (isNaN(qty) || qty <= 0) {
      Alert.alert('Invalid Quantity', 'Please enter a valid quantity greater than 0.');
      return;
    }

    setIsSubmittingReceive(true);
    try {
      if (selectedItemForReceive.is_ecn) {
        const ecnReqId = selectedItemForReceive.ecn_requirement_id || Number(String(selectedItemForReceive.id).replace('ecn_', ''));
        await apiClient.post('/ecn/store/receive', {
          ecn_requirement_id: ecnReqId,
          received_quantity: qty,
          delivery_note_number: deliveryNote,
          remarks: 'Mobile ECN Store Intake',
        });
      } else {
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
      }

      // Optimistic update for partial receipts in current unit view
      if (selectedUnit && selectedUnit.parts) {
        setSelectedUnit(prevUnit => {
          if (!prevUnit) return prevUnit;
          const updatedParts = (prevUnit.parts || []).map(p => {
            if (p.id !== selectedItemForReceive.id) return p;
            const updatedSideStats = { ...(p.side_stats || {}) };
            if (updatedSideStats[receiveSide]) {
              const currentPending = updatedSideStats[receiveSide].pending ?? 0;
              const newPending = Math.max(0, currentPending - qty);
              const currentReceived = updatedSideStats[receiveSide].received ?? 0;
              updatedSideStats[receiveSide] = {
                ...updatedSideStats[receiveSide],
                received: currentReceived + qty,
                pending: newPending,
                status: newPending === 0 ? 'received' : 'partially_received',
              };
            }
            return {
              ...p,
              side_stats: updatedSideStats,
            };
          });
          return {
            ...prevUnit,
            parts: updatedParts,
          };
        });
      }

      setShowReceiveModal(false);
      showToast(`Received ${qty} pcs (${receiveSide}) for ${selectedItemForReceive.standard_part_no}`);
      invalidateMobileCache('store');
      invalidateMobileCache('dashboard');
      invalidateMobileCache('qc');
      loadData('store', false, null, true);
    } catch (err) {
      Alert.alert('Receive Failed', err.response?.data?.message || 'Could not record store receipt.');
    } finally {
      setIsSubmittingReceive(false);  // Always release the lock
    }
  };

  const handleSendToQc = async (itemId) => {
    try {
      if (String(itemId).startsWith('ecn_')) {
        const ecnReqId = Number(String(itemId).replace('ecn_', ''));
        await apiClient.post('/ecn/store/send-to-qc', { ecn_requirement_id: ecnReqId });
      } else {
        await apiClient.post(`/store/items/${itemId}/send-to-qc`);
      }
      showToast('Item dispatched to QC queue');
      invalidateMobileCache('store');
      invalidateMobileCache('qc');
      loadData('store', false, null, true);
    } catch (err) {
      Alert.alert('Error', err.response?.data?.message || 'Failed to dispatch item to QC.');
    }
  };

  // --- QC ACTIONS ---
  const openPhysicalArrivalModal = (item) => {
    setSelectedPhysicalArrivalItem(item);
    const sideStat = item.side_stats?.[unitSideTab] || {};
    const pending = sideStat.qc_pending_arrival || item.received_quantity || 1;
    setPhysicalArrivalQty(String(pending));
    setShowPhysicalArrivalModal(true);
  };

  const submitPhysicalArrival = async () => {
    if (!selectedPhysicalArrivalItem) return;
    const item = selectedPhysicalArrivalItem;
    const sideStat = item.side_stats?.[unitSideTab] || {};
    const pending = sideStat.qc_pending_arrival || item.received_quantity || 1;
    const qty = parseInt(physicalArrivalQty, 10);

    if (isNaN(qty) || qty <= 0 || qty > pending) {
      Alert.alert('Invalid Quantity', `Quantity to receive must be between 1 and pending (${pending}).`);
      return;
    }

    try {
      if (item.is_ecn) {
        const ecnReqId = item.ecn_requirement_id || Number(String(item.id).replace('ecn_', ''));
        await apiClient.post('/ecn/qc/receive', {
          ecn_requirement_id: ecnReqId,
          quantity: qty,
        });
      } else {
        const sideReceipts = sideStat.receipt_items || (item.receipt_items || []).filter(r => r.side === unitSideTab || r.side === 'COMMON');
        const rec = sideReceipts.find(r => ['received', 'sent_to_qc'].includes(r.status));
        await apiClient.post('/qc/receive', {
          receipt_item_id: rec ? rec.id : null,
          bom_item_id: item.id,
          side: unitSideTab,
          quantity: qty,
        });
      }
      setShowPhysicalArrivalModal(false);
      showToast(`Physical Arrival Confirmed: ${qty} pcs of ${item.standard_part_no} (${unitSideTab})`);
      loadData('qc', false);
    } catch (err) {
      Alert.alert('Action Failed', err.response?.data?.message || 'Could not confirm physical QC arrival.');
    }
  };

  const handleRejectQcPhysicalArrival = (receiptItemId, partNo = '') => {
    Alert.alert(
      'Reject Physical Arrival',
      `Reject physical arrival for ${partNo || 'this part'}?\n\nThis sends the part back to Store verification (stock not physically delivered).`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Reject & Return Store',
          style: 'destructive',
          onPress: async () => {
            try {
              const res = await apiClient.post('/qc/reject-arrival', { receipt_item_id: receiptItemId });
              showToast(res.data.message || 'Item returned to store verification.', 'error');
              loadData('qc', false);
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
    const qty = item.received_quantity || item.quantity || 1;
    if (resultType === 'approved') {
      setQcApprovedQty(String(qty));
      setQcPaintQty(String(qty));
      setQcAssemblyQty('0');
      setQcDestination('PAINT');
      setQcRejectedQty('0');
      setQcReworkQty('0');
    } else if (resultType === 'rejected') {
      setQcApprovedQty('0');
      setQcPaintQty('0');
      setQcAssemblyQty('0');
      setQcRejectedQty(String(qty));
      setQcReworkQty('0');
    } else if (resultType === 'rework') {
      setQcApprovedQty('0');
      setQcPaintQty('0');
      setQcAssemblyQty('0');
      setQcRejectedQty('0');
      setQcReworkQty(String(qty));
    } else {
      setQcApprovedQty('0');
      setQcPaintQty('0');
      setQcAssemblyQty('0');
      setQcRejectedQty('0');
      setQcReworkQty('0');
    }
    setQcReason('');
    setQcRemarks('');
    setShowQcModal(true);
  };

  const submitQcInspection = async () => {
    if (!selectedQcItem) return;
    const avail = selectedQcItem.received_quantity || selectedQcItem.quantity || 1;
    const app = parseInt(qcApprovedQty, 10) || 0;
    const paint = parseInt(qcPaintQty, 10) || 0;
    const asm = parseInt(qcAssemblyQty, 10) || 0;
    const rej = parseInt(qcRejectedQty, 10) || 0;
    const rew = parseInt(qcReworkQty, 10) || 0;

    if (qcResult === 'approved') {
      if (app <= 0 || app > avail) {
        Alert.alert('Invalid Quantity', `Approved quantity must be between 1 and available (${avail}).`);
        return;
      }
      if (paint + asm !== app) {
        Alert.alert(
          'Routing Split Error',
          `Paint Quantity (${paint}) + Assembly Quantity (${asm}) = ${paint + asm}\nMust exactly equal Approved Quantity (${app}).`
        );
        return;
      }
    } else if (qcResult === 'rejected') {
      if (rej <= 0 || rej > avail) {
        Alert.alert('Invalid Quantity', `Rejected quantity must be between 1 and available (${avail}).`);
        return;
      }
    } else if (qcResult === 'rework') {
      if (rew <= 0 || rew > avail) {
        Alert.alert('Invalid Quantity', `Rework quantity must be between 1 and available (${avail}).`);
        return;
      }
    }

    const payloadSide = selectedQcItem.side || unitSideTab || 'COMMON';
    const payloadReceiptId = selectedQcItem.id || selectedQcItem.receipt_item_id;
    const payloadBomId = selectedQcItem.bom_item_id || selectedQcItem.bom_item?.id;

    try {
      if (selectedQcItem.is_ecn || selectedQcItem.bom_item?.is_ecn || String(payloadBomId).startsWith('ecn_')) {
        const ecnReqId = selectedQcItem.ecn_requirement_id || selectedQcItem.bom_item?.ecn_requirement_id || Number(String(payloadBomId || payloadReceiptId).replace('ecn_', ''));
        await apiClient.post('/ecn/qc/inspect', {
          ecn_requirement_id: ecnReqId,
          result: qcResult,
          destination: paint > 0 ? (asm > 0 ? null : 'PAINT') : 'ASSEMBLY',
          approved_quantity: qcResult === 'approved' ? app : 0,
          paint_quantity: qcResult === 'approved' ? paint : 0,
          assembly_quantity: qcResult === 'approved' ? asm : 0,
          rejected_quantity: qcResult === 'rejected' ? rej : 0,
          rework_quantity: qcResult === 'rework' ? rew : 0,
          rejection_reason: qcReason,
          rework_reason: qcReason,
          remarks: qcRemarks,
        });
      } else {
        await apiClient.post('/qc/inspect', {
          receipt_item_id: payloadReceiptId,
          bom_item_id: payloadBomId,
          side: payloadSide,
          result: qcResult,
          destination: paint > 0 ? (asm > 0 ? null : 'PAINT') : 'ASSEMBLY',
          approved_quantity: qcResult === 'approved' ? app : 0,
          paint_quantity: qcResult === 'approved' ? paint : 0,
          assembly_quantity: qcResult === 'approved' ? asm : 0,
          rejected_quantity: qcResult === 'rejected' ? rej : 0,
          rework_quantity: qcResult === 'rework' ? rew : 0,
          rejection_reason: qcReason,
          rework_reason: qcReason,
          remarks: qcRemarks,
        });
      }

      setShowQcModal(false);
      showToast(`QC ${qcResult.toUpperCase()}: ${selectedQcItem.bom_item?.standard_part_no || selectedQcItem.standard_part_no || 'Item'} (${payloadSide})`);
      invalidateMobileCache('qc');
      invalidateMobileCache('dashboard');
      invalidateMobileCache('paint');
      invalidateMobileCache('rework');
      invalidateMobileCache('assembly');
      loadData('qc', false, null, true);
    } catch (err) {
      Alert.alert('Inspection Failed', err.response?.data?.message || 'Could not record QC inspection.');
    }
  };

  // --- REWORK ACTIONS ---
  const openReworkModal = (reworkRecord, bomItem) => {
    setSelectedReworkItem({
      ...reworkRecord,
      bom_item: bomItem,
    });
    const avail = reworkRecord.quantity || 1;
    setReworkQty(String(avail));
    setReworkNotes('');
    setShowReworkModal(true);
  };

  const submitReworkCompletion = async () => {
    if (!selectedReworkItem) return;
    const avail = selectedReworkItem.quantity || 1;
    const qty = parseInt(reworkQty, 10);
    if (isNaN(qty) || qty <= 0 || qty > avail) {
      Alert.alert('Invalid Quantity', `Completed quantity must be between 1 and available (${avail}).`);
      return;
    }

    const payloadId = selectedReworkItem.id;
    const payloadBomId = selectedReworkItem.bom_item_id || selectedReworkItem.bom_item?.id;
    const payloadSide = selectedReworkItem.side || unitSideTab || 'COMMON';

    try {
      if (selectedReworkItem.is_ecn || selectedReworkItem.bom_item?.is_ecn || String(payloadBomId).startsWith('ecn_')) {
        const ecnReqId = selectedReworkItem.ecn_requirement_id || selectedReworkItem.bom_item?.ecn_requirement_id || Number(String(payloadBomId || payloadId).replace('ecn_', ''));
        await apiClient.post('/ecn/rework/complete', {
          ecn_requirement_id: ecnReqId,
          quantity: qty,
          completion_notes: reworkNotes || 'Rework completed.',
        });
      } else if (payloadId) {
        await apiClient.post(`/rework/items/${payloadId}/complete`, {
          quantity: qty,
          completion_notes: reworkNotes || 'Rework completed.',
          remarks: reworkNotes || 'Rework completed.',
        });
      } else {
        await apiClient.post('/rework/complete', {
          bom_item_id: payloadBomId,
          side: payloadSide,
          quantity: qty,
          completion_notes: reworkNotes || 'Rework completed.',
          remarks: reworkNotes || 'Rework completed.',
        });
      }
      setShowReworkModal(false);
      showToast(`Rework Completed: ${qty} pcs returned to QC Quality Inspection.`);
      invalidateMobileCache('rework');
      invalidateMobileCache('qc');
      invalidateMobileCache('dashboard');
      loadData('rework', false, null, true);
    } catch (err) {
      Alert.alert('Error', err.response?.data?.message || 'Could not complete rework.');
    }
  };

  // --- STORE RETURNED ACTIONS ---
  const handleProcessReturnedItem = async (item, action) => {
    Alert.alert(
      'Process Returned Part',
      `Confirm marking this returned part as "${action}"?`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Confirm',
          onPress: async () => {
            try {
              await apiClient.post(`/store/items/${item.id}/process-returned`, {
                action,
                remarks: `Processed via Mobile Store App as ${action}`,
              });
              showToast(`Processed as ${action}: ${item.bom_item?.standard_part_no || 'Item'}`);
              invalidateMobileCache('store');
              invalidateMobileCache('dashboard');
              loadData('store', false, null, true);
            } catch (err) {
              Alert.alert('Error', err.response?.data?.message || 'Failed to process returned item.');
            }
          },
        },
      ]
    );
  };

  // --- PAINT ACTIONS ---
  const openPaintModal = (item) => {
    setSelectedPaintItem(item);
    const avail = item.available_paint_quantity || item.approved_quantity || item.quantity || 1;
    setPaintQty(String(avail));
    setPaintType('RAL 7035 Powder Coat');
    setPaintRemarks('');
    setShowPaintModal(true);
  };

  const submitPaintCompletion = async () => {
    if (!selectedPaintItem) return;
    const avail = selectedPaintItem.available_paint_quantity || selectedPaintItem.approved_quantity || selectedPaintItem.quantity || 1;
    const qty = parseInt(paintQty, 10);
    if (isNaN(qty) || qty <= 0 || qty > avail) {
      Alert.alert('Invalid Quantity', `Paint quantity must be between 1 and available (${avail}).`);
      return;
    }

    try {
      const isEcn = selectedPaintItem.is_ecn || selectedPaintItem.bom_item?.is_ecn || String(selectedPaintItem.id).startsWith('ecn_');
      if (isEcn) {
        const ecnReqId = selectedPaintItem.ecn_requirement_id || selectedPaintItem.bom_item?.ecn_requirement_id || Number(String(selectedPaintItem.id).replace('ecn_', ''));
        await apiClient.post('/ecn/paint/complete', {
          ecn_requirement_id: ecnReqId,
          quantity: qty,
          paint_type: paintType,
          remarks: paintRemarks,
        });
      } else {
        const payload = {
          bom_item_id: selectedPaintItem.bom_item_id || selectedPaintItem.bom_item?.id || selectedPaintItem.id,
          side: selectedPaintItem.side || unitSideTab || 'COMMON',
          quantity: qty,
          paint_type: paintType,
          remarks: paintRemarks,
        };
        if (selectedPaintItem.approved_quantity && selectedPaintItem.id) {
          payload.qc_inspection_id = selectedPaintItem.id;
        }
        await apiClient.post('/paint/items', payload);
      }

      setShowPaintModal(false);
      showToast(`Paint Completed: ${qty} pcs of ${selectedPaintItem.bom_item?.standard_part_no || selectedPaintItem.standard_part_no || 'Part'}`);
      invalidateMobileCache('paint');
      invalidateMobileCache('assembly');
      invalidateMobileCache('dashboard');
      loadData('paint', false, null, true);
    } catch (err) {
      Alert.alert('Action Failed', err.response?.data?.message || 'Could not complete paint process.');
    }
  };

  // --- ASSEMBLY ACTIONS ---
  const openAssemblyModal = (part) => {
    setSelectedAssemblyItem(part);
    const sideStat = part.side_stats?.[unitSideTab] || {};
    const asmReady = sideStat.assembly_ready || 1;
    setAssemblyQty(String(asmReady));
    setAssemblyRemarks('');
    setShowAssemblyModal(true);
  };

  const submitAssemblyCompletion = async () => {
    if (!selectedAssemblyItem || isSubmittingAssembly) return;
    const part = selectedAssemblyItem;
    const sideStat = part.side_stats?.[unitSideTab] || {};
    const asmReady = sideStat.assembly_ready || 1;
    const qty = parseInt(assemblyQty, 10);

    if (isNaN(qty) || qty <= 0 || qty > asmReady) {
      Alert.alert('Invalid Quantity', `Assembly quantity must be between 1 and available (${asmReady}).`);
      return;
    }

    setIsSubmittingAssembly(true);
    try {
      if (part.is_ecn || String(part.id).startsWith('ecn_')) {
        const ecnReqId = part.ecn_requirement_id || Number(String(part.id).replace('ecn_', ''));
        const res = await apiClient.post('/ecn/assembly/complete', {
          ecn_requirement_id: ecnReqId,
          quantity: qty,
          remarks: assemblyRemarks || 'Mobile ECN Assembly Complete',
        });
        showToast(res.data?.message || `ECN Assembly Completed: ${qty} pcs of ${part.standard_part_no} (${unitSideTab})`);
      } else {
        const payload = {
          bom_item_id: part.id,
          side: unitSideTab,
          quantity: qty,
          remarks: assemblyRemarks || 'Mobile Assembly Complete',
        };
        const res = await apiClient.post('/assembly/items', payload);
        showToast(res.data?.message || `Assembly Completed: ${qty} pcs of ${part.standard_part_no} (${unitSideTab})`);
      }

      setShowAssemblyModal(false);
      invalidateMobileCache('assembly');
      invalidateMobileCache('dashboard');
      await loadData('assembly', false, null, true);
    } catch (err) {
      Alert.alert('Action Failed', err.response?.data?.message || err.message || 'Could not complete assembly.');
    } finally {
      setIsSubmittingAssembly(false);
    }
  };

  // --- STRICT LINEAGE REVERT ACTIONS ---
  const openRevertModal = (part, dept, side = unitSideTab, specificOption = null) => {
    setRevertTargetItem(part);
    setRevertDept(dept);
    setRevertSide(side);
    const sideStat = part.side_stats?.[side] || part.side_stats?.COMMON || {};
    const options = sideStat.revert_options || [];
    setRevertOptionsList(options);
    const initialOption = specificOption || options[0] || null;
    setSelectedRevertOption(initialOption);
    setRevertQty(String(initialOption?.available_quantity || 1));
    setRevertReason('');
    setShowRevertModal(true);
  };

  const submitRevert = async () => {
    if (!revertTargetItem || isSubmittingRevert) return;
    const qty = parseInt(revertQty, 10);
    const maxAvail = selectedRevertOption?.available_quantity || 1;
    if (isNaN(qty) || qty <= 0 || qty > maxAvail) {
      Alert.alert('Invalid Quantity', `Revert quantity must be between 1 and ${maxAvail}.`);
      return;
    }

    setIsSubmittingRevert(true);
    try {
      if (revertTargetItem.is_ecn || selectedRevertOption?.is_ecn || String(revertTargetItem.id).startsWith('ecn_')) {
        const ecnReqId = revertTargetItem.ecn_requirement_id || Number(String(revertTargetItem.id).replace('ecn_', ''));
        const res = await apiClient.post('/ecn/revert', {
          department: revertDept,
          ecn_requirement_id: ecnReqId,
          quantity: qty,
          source_type: selectedRevertOption?.source_type,
          source_id: selectedRevertOption?.source_id,
          reason: revertReason || 'Mobile ECN revert',
        });
        showToast(res.data?.message || `Successfully reverted ${qty} pcs of ECN ${revertTargetItem.standard_part_no}`);
      } else {
        const payload = {
          department: revertDept,
          bom_item_id: revertTargetItem.id,
          side: revertSide,
          quantity: qty,
          source_type: selectedRevertOption?.source_type,
          source_id: selectedRevertOption?.source_id,
          reason: revertReason || 'Mobile workflow revert',
        };
        const res = await apiClient.post('/workflow/revert', payload);
        showToast(res.data?.message || `Successfully reverted ${qty} pcs of ${revertTargetItem.standard_part_no}`);
      }

      setShowRevertModal(false);

      // Invalidate relevant department caches
      invalidateMobileCache('store');
      invalidateMobileCache('qc');
      invalidateMobileCache('rework');
      invalidateMobileCache('paint');
      invalidateMobileCache('assembly');
      invalidateMobileCache('dashboard');

      await loadData(revertDept, false, null, true);
    } catch (err) {
      Alert.alert('Revert Failed', err.response?.data?.message || err.message || 'Could not complete revert.');
    } finally {
      setIsSubmittingRevert(false);
    }
  };

  // --- GLOBAL / UPPER REVERT HANDLERS ---
  const handleQuickGlobalRevert = async (item, requestedQty) => {
    if (isSubmittingGlobalRevert) return;
    const qty = parseInt(requestedQty, 10);
    const maxAvail = item.available_quantity || 1;
    if (isNaN(qty) || qty <= 0 || qty > maxAvail) {
      Alert.alert('Invalid Quantity', `Quantity must be between 1 and ${maxAvail}.`);
      return;
    }

    setIsSubmittingGlobalRevert(true);
    try {
      const payload = {
        department: activeTab,
        bom_item_id: item.bom_item_id,
        side: item.side,
        quantity: qty,
        source_type: item.source_type,
        source_id: item.source_id,
        reason: 'Global department workflow revert',
      };

      const res = await apiClient.post('/workflow/revert', payload);
      showToast(res.data?.message || `Successfully reverted ${qty} pcs of ${item.standard_part_no}`);

      // Invalidate caches
      invalidateMobileCache('store');
      invalidateMobileCache('qc');
      invalidateMobileCache('rework');
      invalidateMobileCache('paint');
      invalidateMobileCache('assembly');
      invalidateMobileCache('dashboard');

      await loadGlobalRevertItems(activeTab, false);
      await loadData(activeTab, false, null, true);
    } catch (err) {
      Alert.alert('Revert Failed', err.response?.data?.message || err.message || 'Could not complete revert.');
    } finally {
      setIsSubmittingGlobalRevert(false);
    }
  };

  const handleBulkGlobalRevertSubmit = async () => {
    if (isSubmittingGlobalRevert || selectedGlobalRevertIds.size === 0) return;
    const selectedList = globalRevertItems.filter(i => selectedGlobalRevertIds.has(i.id));
    if (!selectedList.length) return;

    const payloadItems = selectedList.map(item => ({
      bom_item_id: item.bom_item_id,
      side: item.side,
      quantity: item.available_quantity,
      source_type: item.source_type,
      source_id: item.source_id,
    }));

    setIsSubmittingGlobalRevert(true);
    try {
      const res = await apiClient.post('/workflow/bulk-revert', {
        department: activeTab,
        items: payloadItems,
        reason: bulkGlobalRevertReason || 'Global bulk workflow revert',
      });

      showToast(res.data?.message || `Successfully reverted ${payloadItems.length} items`);
      setShowBulkGlobalRevertModal(false);
      setSelectedGlobalRevertIds(new Set());
      setIsGlobalRevertSelectionMode(false);
      setBulkGlobalRevertReason('');

      // Invalidate caches
      invalidateMobileCache('store');
      invalidateMobileCache('qc');
      invalidateMobileCache('rework');
      invalidateMobileCache('paint');
      invalidateMobileCache('assembly');
      invalidateMobileCache('dashboard');

      await loadGlobalRevertItems(activeTab, false);
      await loadData(activeTab, false, null, true);
    } catch (err) {
      Alert.alert('Bulk Revert Failed', err.response?.data?.message || err.message || 'Could not execute bulk revert.');
    } finally {
      setIsSubmittingGlobalRevert(false);
    }
  };

  // --- BULK ACTION HANDLERS (Issue 5 & Phase 4) ---
  const [isSubmittingBulk, setIsSubmittingBulk] = useState(false);

  const handleBulkStoreReceive = async (targetItems) => {
    if (isSubmittingBulk) return;
    const itemsPayload = targetItems.map(item => ({
      bom_item_id: item.id,
      side: unitSideTab,
      received_quantity: item.side_stats?.[unitSideTab]?.pending ?? 1,
    }));

    if (!itemsPayload.length) {
      Alert.alert('No Items', 'No items selected for bulk receipt.');
      return;
    }

    setIsSubmittingBulk(true);
    try {
      const res = await apiClient.post('/store/bulk-receive', {
        project_id: selectedProject || targetItems[0]?.project_id,
        delivery_note_number: bulkDeliveryNote || `DN-BULK-${new Date().toISOString().slice(0, 10)}`,
        items: itemsPayload,
      });

      showToast(res.data.message || `Bulk received ${itemsPayload.length} items`);
      clearSelection();
      setShowBulkStoreReceiveModal(false);
      loadData('store', false);
    } catch (err) {
      Alert.alert('Bulk Receive Failed', err.response?.data?.message || 'Could not record bulk receipt.');
    } finally {
      setIsSubmittingBulk(false);
    }
  };

  const handleBulkProcessReturned = async (targetItems, action) => {
    if (isSubmittingBulk) return;
    setIsSubmittingBulk(true);
    let count = 0;
    try {
      for (const item of targetItems) {
        try {
          await apiClient.post(`/store/items/${item.id}/process-returned`, {
            action,
            remarks: `Bulk processed via Mobile Store App as ${action}`,
          });
          count++;
        } catch (e) {}
      }
      showToast(`Bulk processed ${count} returned items as ${action}`);
      clearSelection();
      loadData('store', false);
    } finally {
      setIsSubmittingBulk(false);
    }
  };

  const handleBulkQcArrivalAccept = async (targetItems) => {
    if (isSubmittingBulk) return;
    const receiptIds = [];
    const bomIds = [];

    for (const item of targetItems) {
      if (item.receipt_item_id) {
        receiptIds.push(item.receipt_item_id);
      } else if (item.receipt_items && item.receipt_items.length > 0) {
        const matches = item.receipt_items.filter(r => ['received', 'sent_to_qc', 'store_resident'].includes(r.status) && (r.side === unitSideTab || r.side === 'COMMON'));
        if (matches.length > 0) {
          matches.forEach(m => receiptIds.push(m.id));
        } else {
          bomIds.push(item.id);
        }
      } else if (item.id) {
        bomIds.push(item.id);
      }
    }

    if (!receiptIds.length && !bomIds.length) {
      Alert.alert('No Eligible Items', 'No pending physical arrivals found for the selected items.');
      return;
    }

    setIsSubmittingBulk(true);
    try {
      const res = await apiClient.post('/qc/bulk-receive', {
        receipt_item_ids: receiptIds.length ? receiptIds : undefined,
        bom_item_ids: bomIds.length ? bomIds : undefined,
        side: unitSideTab,
      });
      const count = res.data.processed_count ?? (receiptIds.length + bomIds.length);
      showToast(res.data.message || `Accepted ${count} items in QC`);
      clearSelection();
      loadData('qc', false);
    } catch (err) {
      Alert.alert('Bulk Action Failed', err.response?.data?.message || 'Could not process bulk arrival acceptance.');
    } finally {
      setIsSubmittingBulk(false);
    }
  };

  const handleBulkQcArrivalReject = async (targetItems) => {
    Alert.alert(
      'Bulk Reject Arrival',
      `Reject physical arrival for ${targetItems.length} selected parts?\n\nThey will be sent back to Store.`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Reject & Return Store',
          style: 'destructive',
          onPress: async () => {
            if (isSubmittingBulk) return;
            setIsSubmittingBulk(true);
            let count = 0;
            try {
              for (const item of targetItems) {
                const rec = (item.receipt_items || []).find(r => ['received', 'sent_to_qc'].includes(r.status) && (r.side === unitSideTab || r.side === 'COMMON'));
                if (rec) {
                  try {
                    await apiClient.post('/qc/reject-arrival', { receipt_item_id: rec.id });
                    count++;
                  } catch (e) {}
                }
              }
              showToast(`Rejected ${count} items returned to Store`, 'error');
              clearSelection();
              loadData('qc', false);
            } finally {
              setIsSubmittingBulk(false);
            }
          }
        }
      ]
    );
  };

  const handleBulkQcInspect = async (targetItems, result, destination = null) => {
    if (isSubmittingBulk) return;
    const receiptIds = [];
    const bomIds = [];

    for (const item of targetItems) {
      const sideStat = item.side_stats?.[unitSideTab];
      const sideReceipts = sideStat?.receipt_items || (item.receipt_items || []).filter(r => r.side === unitSideTab || r.side === 'COMMON');
      const rec = sideReceipts.find(r => r.status === 'qc_received');
      if (rec) {
        receiptIds.push(rec.id);
      } else if (item.id) {
        bomIds.push(item.id);
      }
    }

    if (!receiptIds.length && !bomIds.length) {
      Alert.alert('No Eligible Items', `No pending inspection items found for selected parts on ${unitSideTab} side.`);
      return;
    }

    setIsSubmittingBulk(true);
    try {
      const res = await apiClient.post('/qc/bulk-inspect', {
        receipt_item_ids: receiptIds.length ? receiptIds : undefined,
        bom_item_ids: bomIds.length ? bomIds : undefined,
        side: unitSideTab,
        result,
        destination: result === 'approved' ? destination : null,
        rejection_reason: result === 'rejected' ? 'Bulk rejection (Defect/Dimensional)' : null,
        rework_reason: result === 'rework' ? 'Bulk rework required' : null,
        remarks: `Bulk QC inspection marked as ${result.toUpperCase()} (${unitSideTab})`,
      });
      const count = res.data.processed_count ?? (receiptIds.length + bomIds.length);
      showToast(res.data.message || `Processed ${count} items as ${result.toUpperCase()}`);
      clearSelection();
      setShowBulkQcDestinationModal(false);
      loadData('qc', false);
    } catch (err) {
      Alert.alert('Bulk Inspection Failed', err.response?.data?.message || 'Could not process bulk QC inspection.');
    } finally {
      setIsSubmittingBulk(false);
    }
  };

  const handleBulkReworkAction = async (targetItems) => {
    if (isSubmittingBulk) return;
    const reworkIds = targetItems
      .map(item => (item.rework_records || []).find(r => ['pending', 'in_progress'].includes(r.status) && (r.side === unitSideTab || r.side === 'COMMON'))?.id)
      .filter(Boolean);

    if (!reworkIds.length) {
      Alert.alert('No Eligible Items', 'No active rework records available for selected items.');
      return;
    }

    setIsSubmittingBulk(true);
    try {
      const res = await apiClient.post('/rework/bulk-action', {
        rework_record_ids: reworkIds,
        action: 'complete',
        completion_notes: bulkReworkNotes || 'Bulk rework completed.',
      });
      showToast(res.data.message || `Bulk rework completed for ${reworkIds.length} items (Returned to QC)`);
      clearSelection();
      setShowBulkReworkModal(false);
      loadData('rework', false);
    } catch (err) {
      Alert.alert('Bulk Rework Failed', err.response?.data?.message || 'Could not process bulk rework.');
    } finally {
      setIsSubmittingBulk(false);
    }
  };

  const handleBulkPaintComplete = async (targetItems) => {
    if (isSubmittingBulk) return;
    const inspIds = targetItems
      .map(item => (item.qc_inspections || []).find(q => q.approved_quantity > 0 && (q.destination === 'PAINT' || !q.destination) && (q.side === unitSideTab || q.side === 'COMMON'))?.id)
      .filter(Boolean);

    if (!inspIds.length) {
      Alert.alert('No Eligible Items', 'No pending paint records found for the selected items.');
      return;
    }

    setIsSubmittingBulk(true);
    try {
      const res = await apiClient.post('/paint/bulk-complete', {
        qc_inspection_ids: inspIds,
        paint_type: bulkPaintType,
        remarks: 'Bulk Paint operation completed',
      });
      showToast(res.data.message || `Bulk paint completed for ${inspIds.length} items`);
      clearSelection();
      setShowBulkPaintModal(false);
      loadData('paint', false);
    } catch (err) {
      Alert.alert('Bulk Paint Failed', err.response?.data?.message || 'Could not process bulk paint completion.');
    } finally {
      setIsSubmittingBulk(false);
    }
  };

  const handleBulkAssemblyComplete = async (targetItems) => {
    if (isSubmittingBulk) return;
    const assemblyPayload = targetItems.map(item => {
      const sideStat = item.side_stats?.[unitSideTab] || {};
      const sidePaintRecords = sideStat.paint_records || (item.paint_records || []).filter(p => p.side === unitSideTab || p.side === 'COMMON');
      const sideQcInspections = sideStat.qc_inspections || (item.qc_inspections || []).filter(q => q.side === unitSideTab || q.side === 'COMMON');

      const paintRec = sidePaintRecords.find(p => ['completed', 'assembled'].includes(p.status));
      const directQcInsp = sideQcInspections.find(q => q.destination === 'ASSEMBLY' && q.approved_quantity > 0);
      const readyQty = sideStat.assembly_ready || item.metrics?.assembly_ready || 1;

      return {
        bom_item_id: item.id,
        paint_record_id: paintRec ? paintRec.id : null,
        qc_inspection_id: directQcInsp ? directQcInsp.id : null,
        side: unitSideTab,
        quantity: readyQty,
      };
    });

    if (!assemblyPayload.length) {
      Alert.alert('No Eligible Items', 'No items ready for assembly found in the selection.');
      return;
    }

    setIsSubmittingBulk(true);
    try {
      const res = await apiClient.post('/assembly/bulk-complete', {
        items: assemblyPayload,
        remarks: 'Bulk Assembly operation completed',
      });
      showToast(res.data.message || `Bulk assembly completed for ${assemblyPayload.length} items`);
      clearSelection();
      await loadData('assembly', false);
    } catch (err) {
      Alert.alert('Bulk Assembly Failed', err.response?.data?.message || err.message || 'Could not process bulk assembly completion.');
    } finally {
      setIsSubmittingBulk(false);
    }
  };

  const handleBulkRevert = async (targetItems, dept = activeTab) => {
    if (isSubmittingBulk) return;
    const revertPayload = [];

    for (const item of targetItems) {
      const sideStat = item.side_stats?.[unitSideTab] || item.side_stats?.COMMON || {};
      const options = sideStat.revert_options || [];
      for (const opt of options) {
        if (opt.available_quantity > 0) {
          revertPayload.push({
            bom_item_id: item.id,
            side: unitSideTab,
            quantity: opt.available_quantity,
            source_type: opt.source_type,
            source_id: opt.source_id,
          });
        }
      }
    }

    if (!revertPayload.length) {
      Alert.alert('No Revertible Items', 'None of the selected items currently have eligible revert lineage.');
      return;
    }

    Alert.alert(
      'Confirm Bulk Revert',
      `Revert ${revertPayload.length} segments across ${targetItems.length} parts back to their previous department?`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Confirm Bulk Revert',
          style: 'destructive',
          onPress: async () => {
            setIsSubmittingBulk(true);
            try {
              const res = await apiClient.post('/workflow/bulk-revert', {
                department: dept,
                items: revertPayload,
                reason: 'Bulk Revert operation from mobile app',
              });
              showToast(res.data.message || `Successfully reverted ${revertPayload.length} items`);
              clearSelection();
              invalidateMobileCache(dept);
              invalidateMobileCache('dashboard');
              await loadData(dept, false, null, true);
            } catch (err) {
              Alert.alert('Bulk Revert Failed', err.response?.data?.message || err.message || 'Could not complete bulk revert.');
            } finally {
              setIsSubmittingBulk(false);
            }
          }
        }
      ]
    );
  };

  if (!token) {
    return (
      <SafeAreaView style={styles.container}>
        <StatusBar style="dark" />
        <ScrollView contentContainerStyle={styles.scrollContent} keyboardShouldPersistTaps="handled">
          <View style={styles.loginBox}>
            <Image source={require('./assets/logo.png')} style={styles.loginLogo} resizeMode="contain" />

            {errorMsg ? (
              <View style={styles.errorContainer}>
                <Text style={styles.errorText}>{errorMsg}</Text>
              </View>
            ) : null}

            <Text style={[styles.label, { marginTop: 8 }]}>Server Host / IP</Text>
            <TextInput
              style={styles.input}
              value={serverHost}
              onChangeText={setServerHost}
              placeholder="e.g. 192.168.100.36:8080"
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

            {/* Live OTA Update Taskbar Pill */}
            <TouchableOpacity 
              style={styles.otaUpdateBar} 
              onPress={handleCheckOtaUpdate} 
              disabled={otaChecking}
              activeOpacity={0.7}
            >
              <View style={[styles.otaDot, { backgroundColor: otaChecking ? '#f59e0b' : '#10b981' }]} />
              <Text style={styles.otaText}>
                {otaChecking ? 'Checking for updates...' : '⚡ v2.4.0 Live • Tap to Check for Updates'}
              </Text>
              {otaChecking && <ActivityIndicator size="small" color="#64748b" style={{ marginLeft: 6 }} />}
            </TouchableOpacity>
          </View>
        </ScrollView>
      </SafeAreaView>
    );
  }

  return (
    <SafeAreaView style={styles.container}>
      <StatusBar style="dark" />

      {/* Floating Toast Notification (Issue 4) */}
      {toast.visible && (
        <View style={[styles.toastBanner, toast.type === 'error' ? styles.toastError : styles.toastSuccess]}>
          <Text style={styles.toastText}>
            {toast.type === 'error' ? '⚠️ ' : '✓ '}{toast.message}
          </Text>
        </View>
      )}

      {/* Top Header with Logo */}
      <View style={styles.header}>
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: 8, flex: 1 }}>
          <Image source={require('./assets/logo.png')} style={styles.headerLogo} resizeMode="contain" />
          <View style={{ flex: 1 }}>
            <Text style={styles.headerTitle} numberOfLines={1}>FAITH AUTOMATION</Text>
            <Text style={styles.userSubtitle} numberOfLines={1}>
              {user?.name || 'User'} • <Text style={styles.roleBadge}>{userRole}</Text>
            </Text>
          </View>
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

      {/* TOP-LEVEL DEPARTMENT SUBTABS BAR */}
      {activeTab === 'store' && (
        <View style={styles.subTabsContainer}>
          <TouchableOpacity
            style={[styles.subTab, storeSubTab === 'pending' && styles.activeSubTab]}
            onPress={() => { setStoreSubTab('pending'); clearSelection(); }}>
            <Text style={[styles.subTabText, storeSubTab === 'pending' && styles.activeSubTabText]}>
              📦 Pending Intake
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.subTab, storeSubTab === 'revert' && styles.activeSubTabRevert]}
            onPress={() => { setStoreSubTab('revert'); clearSelection(); }}>
            <Text style={[styles.subTabText, storeSubTab === 'revert' && styles.activeSubTabTextRevert]}>
              ↩ Revert
            </Text>
          </TouchableOpacity>
        </View>
      )}

      {activeTab === 'qc' && (
        <View style={styles.subTabsContainer}>
          <TouchableOpacity
            style={[styles.subTab, qcSubTab === 'arrival' && styles.activeSubTab]}
            onPress={() => { setQcSubTab('arrival'); clearSelection(); }}>
            <Text style={[styles.subTabText, qcSubTab === 'arrival' && styles.activeSubTabText]}>
              📦 1. Arrival
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.subTab, qcSubTab === 'inspection' && styles.activeSubTab]}
            onPress={() => { setQcSubTab('inspection'); clearSelection(); }}>
            <Text style={[styles.subTabText, qcSubTab === 'inspection' && styles.activeSubTabText]}>
              🔬 2. Inspection
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.subTab, qcSubTab === 'revert' && styles.activeSubTabRevert]}
            onPress={() => { setQcSubTab('revert'); clearSelection(); }}>
            <Text style={[styles.subTabText, qcSubTab === 'revert' && styles.activeSubTabTextRevert]}>
              ↩ Revert
            </Text>
          </TouchableOpacity>
        </View>
      )}

      {activeTab === 'rework' && (
        <View style={styles.subTabsContainer}>
          <TouchableOpacity
            style={[styles.subTab, reworkSubTab === 'queue' && styles.activeSubTab]}
            onPress={() => { setReworkSubTab('queue'); clearSelection(); }}>
            <Text style={[styles.subTabText, reworkSubTab === 'queue' && styles.activeSubTabText]}>
              🛠️ Rework Queue
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.subTab, reworkSubTab === 'revert' && styles.activeSubTabRevert]}
            onPress={() => { setReworkSubTab('revert'); clearSelection(); }}>
            <Text style={[styles.subTabText, reworkSubTab === 'revert' && styles.activeSubTabTextRevert]}>
              ↩ Revert
            </Text>
          </TouchableOpacity>
        </View>
      )}

      {activeTab === 'paint' && (
        <View style={styles.subTabsContainer}>
          <TouchableOpacity
            style={[styles.subTab, paintSubTab === 'queue' && styles.activeSubTab]}
            onPress={() => { setPaintSubTab('queue'); clearSelection(); }}>
            <Text style={[styles.subTabText, paintSubTab === 'queue' && styles.activeSubTabText]}>
              🎨 Paint Queue
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.subTab, paintSubTab === 'revert' && styles.activeSubTabRevert]}
            onPress={() => { setPaintSubTab('revert'); clearSelection(); }}>
            <Text style={[styles.subTabText, paintSubTab === 'revert' && styles.activeSubTabTextRevert]}>
              ↩ Revert
            </Text>
          </TouchableOpacity>
        </View>
      )}

      {activeTab === 'assembly' && (
        <View style={styles.subTabsContainer}>
          <TouchableOpacity
            style={[styles.subTab, assemblySubTab === 'queue' && styles.activeSubTab]}
            onPress={() => { setAssemblySubTab('queue'); clearSelection(); }}>
            <Text style={[styles.subTabText, assemblySubTab === 'queue' && styles.activeSubTabText]}>
              ⚙️ Queue
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.subTab, assemblySubTab === 'completed' && styles.activeSubTab]}
            onPress={() => { setAssemblySubTab('completed'); clearSelection(); }}>
            <Text style={[styles.subTabText, assemblySubTab === 'completed' && styles.activeSubTabText]}>
              🏁 Done
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.subTab, assemblySubTab === 'revert' && styles.activeSubTabRevert]}
            onPress={() => { setAssemblySubTab('revert'); clearSelection(); }}>
            <Text style={[styles.subTabText, assemblySubTab === 'revert' && styles.activeSubTabTextRevert]}>
              ↩ Revert
            </Text>
          </TouchableOpacity>
        </View>
      )}

      {/* Search & Filter Bar - Tab-Isolated Search (Part 13) */}
      {['store', 'qc', 'paint', 'assembly', 'rework', 'purchase'].includes(activeTab) && (
        <View style={styles.searchBarContainer}>
          <TextInput
            style={[styles.searchInput, { flex: 1 }]}
            placeholder={getSearchPlaceholder()}
            placeholderTextColor="#9ca3af"
            value={currentSearchQuery}
            onChangeText={handleSearchChange}
          />
          {currentSearchQuery !== '' && (
            <TouchableOpacity style={styles.clearSearchBtn} onPress={handleClearSearch}>
              <Text style={styles.clearSearchBtnText}>✕</Text>
            </TouchableOpacity>
          )}
          {['store', 'qc'].includes(activeTab) && (
            <TouchableOpacity style={styles.filterBtn} onPress={() => setShowFilterModal(true)}>
              <Text style={styles.filterBtnText}>Filters</Text>
            </TouchableOpacity>
          )}
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

      {/* Paint Tab Status Filter Buttons (Part 10) */}
      {activeTab === 'paint' && !selectedProject && (
        <View style={{ flexDirection: 'row', gap: 6, marginHorizontal: 16, marginBottom: 8 }}>
          <TouchableOpacity
            style={[styles.chipBtn, paintStatusFilter === 'all' && styles.chipBtnActive]}
            onPress={() => setPaintStatusFilter('all')}>
            <Text style={[styles.chipBtnText, paintStatusFilter === 'all' && styles.chipBtnTextActive]}>
              All ({projects.length})
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.chipBtn, paintStatusFilter === 'active' && styles.chipBtnActive]}
            onPress={() => setPaintStatusFilter('active')}>
            <Text style={[styles.chipBtnText, paintStatusFilter === 'active' && styles.chipBtnTextActive]}>
              Active ({projects.filter(p => p.eligible_qty > 0 || !p.is_complete).length})
            </Text>
          </TouchableOpacity>
          <TouchableOpacity
            style={[styles.chipBtn, paintStatusFilter === 'completed' && styles.chipBtnActive]}
            onPress={() => setPaintStatusFilter('completed')}>
            <Text style={[styles.chipBtnText, paintStatusFilter === 'completed' && styles.chipBtnTextActive]}>
              Completed ({projects.filter(p => p.is_complete).length})
            </Text>
          </TouchableOpacity>
        </View>
      )}

      {/* STICKY HIERARCHY CONTEXT HEADER (Always fixed outside ScrollView while content scrolls) */}
      {selectedProject && (
        <View style={styles.hierarchyNavRow}>
          <Text style={styles.hierarchyNavTitle} numberOfLines={1} ellipsizeMode="tail">
            {hierarchyProject ? (hierarchyProject.project_code || hierarchyProject.name) : 'Project'}
            {selectedJig ? ` › JIG: ${selectedJig.jig_name}` : ''}
            {selectedUnit ? ` › ${selectedUnit.unit_no} › ${unitSideTab}` : ''}
            {selectedUnit && activeTab === 'qc' ? ` (${qcSubTab === 'arrival' ? 'Arrival' : 'Inspection'})` : ''}
          </Text>
          <TouchableOpacity
            style={styles.backLevelBtn}
            onPress={() => {
              scrollToTop(false);
              clearSelection();
              if (selectedUnit) setSelectedUnit(null);
              else if (selectedJig) setSelectedJig(null);
              else handleResetProject();
            }}>
            <Text style={styles.backLevelBtnText}>‹ Back</Text>
          </TouchableOpacity>
        </View>
      )}

      {/* Main Content Scroll View */}
      <ScrollView
        ref={mainScrollRef}
        style={styles.content}
        contentContainerStyle={[
          styles.content,
          ((selectedItemIds.size > 0 && selectedUnit) || (isTopLevelRevertTab && isGlobalRevertSelectionMode && selectedGlobalRevertIds.size > 0)) ? { paddingBottom: 120 } : null
        ]}
        refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}>
        {loading && !refreshing && !selectedProject && items.length === 0 && projects.length === 0 ? (
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
            <View style={[styles.card, { backgroundColor: '#7c3aed' }]}>
              <Text style={styles.cardLabel}>Paint Active</Text>
              <Text style={styles.cardValue}>{summary?.parts_in_paint || 0}</Text>
            </View>
            <View style={[styles.card, { backgroundColor: '#db2777' }]}>
              <Text style={styles.cardLabel}>Assembly Active</Text>
              <Text style={styles.cardValue}>
                {summary?.parts_in_assembly || 0}
                <Text style={{ fontSize: 13, fontWeight: 'normal', color: '#fbcfe8' }}> ({summary?.assembly_completed || 0} Done)</Text>
              </Text>
            </View>
            <View style={[styles.card, { backgroundColor: '#ef4444' }]}>
              <Text style={styles.cardLabel}>Purchase Queue</Text>
              <Text style={styles.cardValue}>{summary?.pending_purchase || 0}</Text>
            </View>
          </View>
        ) : ['store', 'qc', 'rework', 'paint', 'assembly'].includes(activeTab) ? (
          // MOBILE UNIFIED 4-LEVEL DRILLDOWN VIEW (Page-wise Search Enabled across all departments)
          <View style={styles.listContainer}>
            {/* UPPER / GLOBAL REVERT QUEUE (when upper revert subtab is active and no unit opened) */}
            {isTopLevelRevertTab ? (
              <View>
                <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                  <Text style={styles.sectionHeader}>
                    ↩ {activeTab.toUpperCase()} GLOBAL REVERT QUEUE ({globalRevertItems.length})
                  </Text>
                  {selectedProject && (
                    <TouchableOpacity
                      style={styles.clearProjectFilterBtn}
                      onPress={() => setSelectedProject(null)}>
                      <Text style={styles.clearProjectFilterBtnText}>All Projects ✕</Text>
                    </TouchableOpacity>
                  )}
                </View>

                {/* Multi-Selection Control Bar */}
                <View style={styles.selectionControlBar}>
                  <TouchableOpacity
                    style={styles.selectionToggleBtn}
                    onPress={() => {
                      if (isGlobalRevertSelectionMode) {
                        setSelectedGlobalRevertIds(new Set());
                        setIsGlobalRevertSelectionMode(false);
                      } else {
                        setIsGlobalRevertSelectionMode(true);
                      }
                    }}>
                    <Text style={styles.selectionToggleText}>
                      {isGlobalRevertSelectionMode ? '✕ Cancel Selection' : '☑ Multi-Select'}
                    </Text>
                  </TouchableOpacity>

                  {isGlobalRevertSelectionMode && (
                    <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                      <TouchableOpacity
                        style={styles.selectAllBtn}
                        onPress={() => {
                          const allIds = new Set(globalRevertItems.map(i => i.id));
                          setSelectedGlobalRevertIds(allIds);
                        }}>
                        <Text style={styles.selectAllBtnText}>Select All ({globalRevertItems.length})</Text>
                      </TouchableOpacity>
                      {selectedGlobalRevertIds.size > 0 && (
                        <TouchableOpacity
                          style={styles.clearSelectBtn}
                          onPress={() => setSelectedGlobalRevertIds(new Set())}>
                          <Text style={styles.clearSelectBtnText}>Clear ({selectedGlobalRevertIds.size})</Text>
                        </TouchableOpacity>
                      )}
                    </View>
                  )}
                </View>

                {isLoadingGlobalRevert && globalRevertItems.length === 0 ? (
                  <View style={{ paddingVertical: 30, alignItems: 'center' }}>
                    <ActivityIndicator size="large" color="#dc2626" />
                    <Text style={{ marginTop: 8, color: '#64748b', fontSize: 13 }}>Loading revertible parts...</Text>
                  </View>
                ) : (
                  <>
                    {globalRevertItems.map((item) => {
                      const isSelected = selectedGlobalRevertIds.has(item.id);
                      return (
                        <TouchableOpacity
                          key={item.id}
                          activeOpacity={0.85}
                          onLongPress={() => {
                            setIsGlobalRevertSelectionMode(true);
                            setSelectedGlobalRevertIds(prev => {
                              const next = new Set(prev);
                              if (next.has(item.id)) next.delete(item.id);
                              else next.add(item.id);
                              return next;
                            });
                          }}
                          onPress={() => {
                            if (isGlobalRevertSelectionMode) {
                              setSelectedGlobalRevertIds(prev => {
                                const next = new Set(prev);
                                if (next.has(item.id)) next.delete(item.id);
                                else next.add(item.id);
                                return next;
                              });
                            }
                          }}
                          style={[
                            styles.itemCard,
                            isSelected && { borderColor: '#dc2626', borderWidth: 2, backgroundColor: '#fef2f2' }
                          ]}>
                          {/* Row 1: Context Pill + Part No + Side */}
                          <View style={styles.itemHeader}>
                            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6, flex: 1 }}>
                              {isGlobalRevertSelectionMode && (
                                <View style={[styles.checkboxCircle, isSelected && { backgroundColor: '#dc2626', borderColor: '#dc2626' }]}>
                                  {isSelected && <Text style={styles.checkmarkText}>✓</Text>}
                                </View>
                              )}
                              <Text style={styles.itemPartNo} numberOfLines={1}>
                                {item.project_code} • {item.jig_name ? `${item.jig_name} • ` : ''}{item.unit_no ? `${item.unit_no} • ` : ''}{item.standard_part_no}
                              </Text>
                              <View style={item.side === 'LH' ? styles.sidePillLh : styles.sidePillRh}>
                                <Text style={item.side === 'LH' ? styles.sidePillTextLh : styles.sidePillTextRh}>
                                  {item.side}
                                </Text>
                              </View>
                            </View>
                          </View>

                          {/* Row 2: Lineage Destination Info */}
                          <View style={{ marginTop: 2, marginBottom: 4 }}>
                            <Text style={[styles.itemSubText, { fontSize: 11, color: '#475569' }]}>
                              To: <Text style={{ fontWeight: '700', color: '#dc2626' }}>{item.target_label || item.to_department}</Text>
                            </Text>
                          </View>

                          {/* Row 3: Compact Inline Quantity Stepper & Single Revert Action */}
                          <CompactInlineRevertRow
                            item={item}
                            disabled={isSubmittingGlobalRevert || isGlobalRevertSelectionMode}
                            onRevert={handleQuickGlobalRevert}
                          />
                        </TouchableOpacity>
                      );
                    })}

                    {globalRevertItems.length === 0 && (
                      <View style={styles.emptyState}>
                        <Text style={[styles.emptyStateText, { color: '#64748b' }]}>
                          ✓ No active revertible parts in {activeTab.toUpperCase()}.
                        </Text>
                      </View>
                    )}
                  </>
                )}
              </View>
            ) : (
              <>
                {/* LEVEL 1: PROJECTS GRID (when no project selected) */}
                {!selectedProject && (
              <View>
                <Text style={styles.sectionHeader}>
                  SELECT {activeTab.toUpperCase()} PROJECT ({
                    projects
                      .filter(p => {
                        if (activeTab === 'paint') {
                          if (paintStatusFilter === 'active' && (p.eligible_qty === 0 && p.is_complete)) return false;
                          if (paintStatusFilter === 'completed' && !p.is_complete) return false;
                        }
                        if (!currentSearchQuery) return true;
                        const q = currentSearchQuery.toLowerCase().trim();
                        return (p.name || '').toLowerCase().includes(q) || (p.project_code || '').toLowerCase().includes(q);
                      }).length
                  })
                </Text>
                {projects
                  .filter(p => {
                    if (activeTab === 'paint') {
                      if (paintStatusFilter === 'active' && (p.eligible_qty === 0 && p.is_complete)) return false;
                      if (paintStatusFilter === 'completed' && !p.is_complete) return false;
                    }
                    if (!currentSearchQuery) return true;
                    const q = currentSearchQuery.toLowerCase().trim();
                    return (p.name || '').toLowerCase().includes(q) || (p.project_code || '').toLowerCase().includes(q);
                  })
                  .map((proj) => (
                  <TouchableOpacity
                    key={proj.id}
                    style={[
                      styles.jigCard,
                      proj.is_complete ? styles.jigCardComplete : styles.jigCardIncomplete
                    ]}
                    onPress={() => handleSelectProject(proj.id)}>
                    <View style={styles.itemHeader}>
                      <Text style={[styles.jigName, proj.is_complete && { color: '#15803d' }]}>
                        📁 {proj.name}
                      </Text>
                      <Text style={[styles.jigBadge, proj.is_complete ? styles.jigBadgeComplete : styles.jigBadgeIncomplete]}>
                        {proj.is_complete ? '100% DONE' : `${proj.completion_pct || 0}%`}
                      </Text>
                    </View>
                    <Text style={styles.itemSubText}>
                      Project Code: {proj.project_code || 'N/A'} • Required: {proj.total_required}
                    </Text>
                    <View style={styles.progressBarBg}>
                      <View style={[styles.progressBarFill, { width: `${proj.completion_pct || 0}%`, backgroundColor: proj.is_complete ? '#16a34a' : '#2563eb' }]} />
                    </View>
                    <Text style={styles.tapExploreText}>Tap to explore JIGs inside {proj.name} ›</Text>
                  </TouchableOpacity>
                ))}
                {projects.filter(p => {
                  if (activeTab === 'paint') {
                    if (paintStatusFilter === 'active' && (p.eligible_qty === 0 && p.is_complete)) return false;
                    if (paintStatusFilter === 'completed' && !p.is_complete) return false;
                  }
                  if (!currentSearchQuery) return true;
                  const q = currentSearchQuery.toLowerCase().trim();
                  return (p.name || '').toLowerCase().includes(q) || (p.project_code || '').toLowerCase().includes(q);
                }).length === 0 && (
                  <View style={styles.emptyState}>
                    <Text style={styles.emptyStateText}>No projects match "{currentSearchQuery}".</Text>
                  </View>
                )}
              </View>
            )}

            {/* LEVEL 2-4: JIG & UNIT DRILLDOWN (when project is selected) */}
            {selectedProject && (
              <View>
                {/* LEVEL 2: JIG CARDS GRID (when no JIG selected) */}
                {!selectedJig && (
                  <View>
                    <Text style={styles.sectionHeader}>
                      {activeTab.toUpperCase()} JIGS ({
                        hierarchyJigs.filter(j => {
                          if (!currentSearchQuery) return true;
                          const q = currentSearchQuery.toLowerCase().trim();
                          return (j.jig_name || '').toLowerCase().includes(q);
                        }).length
                      })
                    </Text>
                    {hierarchyJigs
                      .filter(j => {
                        if (!currentSearchQuery) return true;
                        const q = currentSearchQuery.toLowerCase().trim();
                        return (j.jig_name || '').toLowerCase().includes(q);
                      })
                      .map((jig) => (
                      <TouchableOpacity
                        key={jig.jig_name}
                        style={[
                          styles.jigCard,
                          jig.is_complete ? styles.jigCardComplete : styles.jigCardIncomplete
                        ]}
                        onPress={() => {
                          setSelectedJig(jig);
                          setSelectedUnit(null);
                        }}>
                        <View style={styles.itemHeader}>
                          <Text style={[styles.jigName, jig.is_complete && { color: '#15803d' }]}>
                            {jig.is_complete ? '✓ ' : '⚙️ '}JIG: {jig.jig_name}
                          </Text>
                          <Text style={[styles.jigBadge, jig.is_complete ? styles.jigBadgeComplete : styles.jigBadgeIncomplete]}>
                            {jig.is_complete ? '100% DONE' : `${jig.completion_pct}%`}
                          </Text>
                        </View>
                        <Text style={styles.itemSubText}>
                          {jig.complete_units} / {jig.total_units} Units Complete • {jig.total_parts} Parts
                        </Text>
                        <View style={styles.progressBarBg}>
                          <View style={[styles.progressBarFill, { width: `${jig.completion_pct}%`, backgroundColor: jig.is_complete ? '#16a34a' : '#2563eb' }]} />
                        </View>
                        <Text style={styles.tapExploreText}>Tap to explore Units inside {jig.jig_name} ›</Text>
                      </TouchableOpacity>
                    ))}
                    {hierarchyJigs.filter(j => {
                      if (!currentSearchQuery) return true;
                      const q = currentSearchQuery.toLowerCase().trim();
                      return (j.jig_name || '').toLowerCase().includes(q);
                    }).length === 0 && (
                      <View style={styles.emptyState}>
                        <Text style={styles.emptyStateText}>No JIGs match "{currentSearchQuery}".</Text>
                      </View>
                    )}
                  </View>
                )}

                {/* LEVEL 3: UNITS LIST (when JIG selected, no Unit selected) */}
                {selectedJig && !selectedUnit && (() => {
                  const getSideEligibility = (unit, side) => {
                    if (!unit) return { eligible: false, count: 0, required: 0, received: 0, pct: 0, label: '', buttonText: '' };
                    const sideObj = unit.sides?.[side] || {};
                    const sideParts = (unit.parts || []).filter(p => p.side_stats?.[side] || p.side_stats?.COMMON);
                    const sideMetrics = sideObj.metrics || {};
                    const hasSideParts = sideParts.length > 0 || !!unit.sides?.[side];

                    const totalRequired = sideObj.total_required ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.required || p.side_stats?.COMMON?.required || 0), 0);
                    const totalReceived = sideObj.total_received ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.received || p.side_stats?.COMMON?.received || 0), 0);
                    const totalPending = sideObj.pending_quantity ?? Math.max(0, totalRequired - totalReceived);

                    let count = 0;
                    let label = '';
                    let buttonText = `Open ${side} ›`;

                    if (activeTab === 'paint') {
                      if (paintSubTab === 'revert') {
                        const revQty = sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.total_revertible || p.side_stats?.COMMON?.total_revertible || 0), 0);
                        count = revQty;
                        label = `${revQty} Revertible to QC`;
                        buttonText = revQty > 0 ? `Open ${side} (${revQty} Revert) ›` : `Open ${side} ›`;
                      } else {
                        const readyQty = sideMetrics.paint_ready ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.paint_ready || p.side_stats?.COMMON?.paint_ready || 0), 0);
                        const compQty = sideMetrics.paint_completed ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.paint_completed || p.side_stats?.COMMON?.paint_completed || 0), 0);
                        count = readyQty;
                        label = `${readyQty} Ready • ${compQty} Done`;
                        buttonText = readyQty > 0 ? `Open ${side} (${readyQty} Ready) ›` : `Open ${side} ›`;
                      }
                    } else if (activeTab === 'assembly') {
                      if (assemblySubTab === 'revert') {
                        const revQty = sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.total_revertible || p.side_stats?.COMMON?.total_revertible || 0), 0);
                        count = revQty;
                        label = `${revQty} Revertible`;
                        buttonText = revQty > 0 ? `Open ${side} (${revQty} Revert) ›` : `Open ${side} ›`;
                      } else if (assemblySubTab === 'completed') {
                        const compQty = sideMetrics.assembly_completed ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.assembly_completed || p.side_stats?.COMMON?.assembly_completed || 0), 0);
                        count = compQty;
                        label = `${compQty} Assembled`;
                        buttonText = compQty > 0 ? `Open ${side} (${compQty} Done) ›` : `Open ${side} ›`;
                      } else {
                        const readyQty = sideMetrics.assembly_ready ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.assembly_ready || p.side_stats?.COMMON?.assembly_ready || 0), 0);
                        const compQty = sideMetrics.assembly_completed ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.assembly_completed || p.side_stats?.COMMON?.assembly_completed || 0), 0);
                        count = readyQty;
                        label = `${readyQty} Ready • ${compQty} Assembled`;
                        buttonText = readyQty > 0 ? `Open ${side} (${readyQty} Ready) ›` : `Open ${side} ›`;
                      }
                    } else if (activeTab === 'qc') {
                      if (qcSubTab === 'revert') {
                        const revQty = sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.total_revertible || p.side_stats?.COMMON?.total_revertible || 0), 0);
                        count = revQty;
                        label = `${revQty} Revertible to Store`;
                        buttonText = revQty > 0 ? `Open ${side} (${revQty} Revert) ›` : `Open ${side} ›`;
                      } else if (qcSubTab === 'arrival') {
                        const pendingArrival = sideMetrics.qc_pending_arrival ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.qc_pending_arrival || p.side_stats?.COMMON?.qc_pending_arrival || 0), 0);
                        count = pendingArrival;
                        label = `${pendingArrival} Pending Arrival`;
                        buttonText = pendingArrival > 0 ? `Open ${side} (${pendingArrival} Arrival) ›` : `Open ${side} ›`;
                      } else {
                        const pendingInsp = sideMetrics.qc_pending_inspection ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.qc_pending_inspection || p.side_stats?.COMMON?.qc_pending_inspection || 0), 0);
                        const approved = sideMetrics.qc_approved ?? sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.qc_approved || p.side_stats?.COMMON?.qc_approved || 0), 0);
                        count = pendingInsp;
                        label = `${pendingInsp} Pending QC • ${approved} App`;
                        buttonText = pendingInsp > 0 ? `Open ${side} (${pendingInsp} Inspect) ›` : `Open ${side} ›`;
                      }
                    } else if (activeTab === 'rework') {
                      if (reworkSubTab === 'revert') {
                        const revQty = sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.total_revertible || p.side_stats?.COMMON?.total_revertible || 0), 0);
                        count = revQty;
                        label = `${revQty} Revertible to QC`;
                        buttonText = revQty > 0 ? `Open ${side} (${revQty} Revert) ›` : `Open ${side} ›`;
                      } else {
                        const rewPend = sideMetrics.rework_pending ?? 0;
                        const rewProg = sideMetrics.rework_in_progress ?? 0;
                        const rewComp = sideMetrics.rework_completed ?? 0;
                        count = rewPend + rewProg;
                        label = count > 0 ? `${count} in Rework` : `${rewComp} Completed`;
                        buttonText = count > 0 ? `Open ${side} (${count}) ›` : `Open ${side} ›`;
                      }
                    } else {
                      // Store
                      if (storeSubTab === 'revert') {
                        const revQty = sideParts.reduce((acc, p) => acc + (p.side_stats?.[side]?.total_revertible || p.side_stats?.COMMON?.total_revertible || 0), 0);
                        count = revQty;
                        label = `${revQty} Revertible to Supplier`;
                        buttonText = revQty > 0 ? `Open ${side} (${revQty} Revert) ›` : `Open ${side} ›`;
                      } else {
                        count = totalPending;
                        label = `Req: ${totalRequired} • Rec: ${totalReceived}`;
                        buttonText = totalPending > 0 ? `Open ${side} (${totalPending} Pen) ›` : `Open ${side} (Done) ›`;
                      }
                    }

                    return {
                      eligible: hasSideParts,
                      count,
                      required: totalRequired,
                      received: totalReceived,
                      pct: sideObj.completion_pct ?? 0,
                      label,
                      buttonText,
                    };
                  };

                  const filteredUnits = (selectedJig.units || []).filter(unit => {
                    if (!currentSearchQuery) return true;
                    const q = currentSearchQuery.toLowerCase().trim();
                    const uNo = (unit.unit_no || '').toLowerCase();
                    const cleanQ = q.replace(/^unit\s*/i, '').trim();
                    return uNo.includes(q) || (cleanQ && uNo.includes(cleanQ));
                  });

                  return (
                    <View>
                      <Text style={styles.sectionHeader}>
                        UNITS IN JIG: {selectedJig.jig_name} ({filteredUnits.length})
                      </Text>

                      {filteredUnits.map((unit) => {
                        const lhElig = getSideEligibility(unit, 'LH');
                        const rhElig = getSideEligibility(unit, 'RH');

                        const hasLh = (unit.parts || []).some(p => p.side_stats?.LH || p.side_stats?.COMMON) || !!unit.sides?.LH;
                        const hasRh = (unit.parts || []).some(p => p.side_stats?.RH || p.side_stats?.COMMON) || !!unit.sides?.RH;
                        const showLH = hasLh || !hasRh;
                        const showRH = hasRh || !hasLh;

                        const defaultSide = (showLH && lhElig.eligible) ? 'LH' : (showRH && rhElig.eligible) ? 'RH' : (showLH ? 'LH' : 'RH');

                        const openUnit = (sideToOpen = defaultSide) => {
                          scrollToTop(false);
                          clearSelection();
                          setSelectedUnit(unit);
                          setUnitSideTab(sideToOpen);
                        };

                        return (
                          <TouchableOpacity
                            key={unit.unit_no}
                            activeOpacity={0.85}
                            onPress={() => openUnit(defaultSide)}
                            style={[
                              styles.unitCard,
                              unit.is_complete ? styles.unitCardComplete : styles.unitCardIncomplete,
                              { padding: 10 }
                            ]}>
                            {/* Single Unit Header */}
                            <View style={[styles.itemHeader, { marginBottom: 8, paddingBottom: 6, borderBottomWidth: 1, borderBottomColor: '#f1f5f9' }]}>
                              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                                <Text style={[styles.unitTitle, unit.is_complete && { color: '#15803d' }]}>
                                  {unit.is_complete ? '✓ ' : '📦 '}{unit.unit_no}
                                </Text>
                              </View>
                              <Text style={[styles.unitBadge, unit.is_complete ? styles.jigBadgeComplete : styles.unitBadgePending]}>
                                {unit.is_complete ? 'COMPLETED' : `${unit.completion_pct}%`}
                              </Text>
                            </View>

                            {/* Responsive Side Panels: Single side full width or Dual side split */}
                            <View style={{ flexDirection: 'row', gap: (showLH && showRH) ? 8 : 0 }}>
                              {/* LH Touchable Section */}
                              {showLH && (
                                <TouchableOpacity
                                  style={[
                                    styles.mobileSidePanel,
                                    { flex: 1,
                                      borderColor: lhElig.eligible ? '#0ea5e9' : '#e2e8f0',
                                      backgroundColor: lhElig.eligible ? '#f0f9ff' : '#f8fafc' }
                                  ]}
                                  onPress={() => openUnit('LH')}>
                                  <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                    <View style={styles.sidePillLh}>
                                      <Text style={styles.sidePillTextLh}>🔵 LH</Text>
                                    </View>
                                    <Text style={{ fontSize: 10.5, fontWeight: '700', color: '#0369a1' }}>
                                      {lhElig.pct}%
                                    </Text>
                                  </View>
                                  <Text style={{ fontSize: 11, fontWeight: '700', color: '#1e293b', marginBottom: 2 }}>
                                    {lhElig.label}
                                  </Text>
                                  <Text style={{ fontSize: 10.5, fontWeight: '700', color: lhElig.eligible ? '#0284c7' : '#94a3b8', marginTop: 6 }}>
                                    {lhElig.buttonText}
                                  </Text>
                                </TouchableOpacity>
                              )}

                              {/* RH Touchable Section */}
                              {showRH && (
                                <TouchableOpacity
                                  style={[
                                    styles.mobileSidePanel,
                                    { flex: 1,
                                      borderColor: rhElig.eligible ? '#6366f1' : '#e2e8f0',
                                      backgroundColor: rhElig.eligible ? '#eef2ff' : '#f8fafc' }
                                  ]}
                                  onPress={() => openUnit('RH')}>
                                  <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 4 }}>
                                    <View style={styles.sidePillRh}>
                                      <Text style={styles.sidePillTextRh}>🔷 RH</Text>
                                    </View>
                                    <Text style={{ fontSize: 10.5, fontWeight: '700', color: '#4338ca' }}>
                                      {rhElig.pct}%
                                    </Text>
                                  </View>
                                  <Text style={{ fontSize: 11, fontWeight: '700', color: '#1e293b', marginBottom: 2 }}>
                                    {rhElig.label}
                                  </Text>
                                  <Text style={{ fontSize: 10.5, fontWeight: '700', color: rhElig.eligible ? '#4f46e5' : '#94a3b8', marginTop: 6 }}>
                                    {rhElig.buttonText}
                                  </Text>
                                </TouchableOpacity>
                              )}
                            </View>
                          </TouchableOpacity>
                        );
                      })}

                      {filteredUnits.length === 0 && (
                        <View style={styles.emptyState}>
                          <Text style={styles.emptyStateText}>
                            {currentSearchQuery
                              ? `No units match "${currentSearchQuery}".`
                              : `No units found in this JIG.`}
                          </Text>
                        </View>
                      )}
                    </View>
                  );
                })()}

                {/* LEVEL 4: PARTS LIST (when Unit selected) */}
                {selectedUnit && (() => {
                  const partsList = Array.isArray(selectedUnit.parts) ? selectedUnit.parts : [];
                  const visibleParts = partsList.filter(item => {
                    if (!item) return false;
                    const matchSide = unitSideTab === 'LH'
                      ? !!(item.side_stats?.LH || item.side_stats?.COMMON)
                      : !!(item.side_stats?.RH || item.side_stats?.COMMON);
                    if (!matchSide) return false;

                    const currentSideStats = unitSideTab === 'LH' ? (item.side_stats?.LH || item.side_stats?.COMMON || {}) : (item.side_stats?.RH || item.side_stats?.COMMON || {});

                    // Store subtabs
                    if (activeTab === 'store') {
                      if (storeSubTab === 'pending' && !((currentSideStats.pending ?? 0) > 0)) return false;
                      if (storeSubTab === 'revert' && !((currentSideStats.total_revertible ?? 0) > 0)) return false;
                    }

                    // QC subtabs
                    if (activeTab === 'qc') {
                      if (qcSubTab === 'arrival' && !((currentSideStats.qc_pending_arrival ?? 0) > 0)) return false;
                      if (qcSubTab === 'inspection' && !((currentSideStats.qc_pending_inspection ?? 0) > 0)) return false;
                      if (qcSubTab === 'revert' && !((currentSideStats.total_revertible ?? 0) > 0)) return false;
                    }

                    // Rework subtabs
                    if (activeTab === 'rework') {
                      if (reworkSubTab === 'queue' && !(((currentSideStats.rework_pending ?? 0) + (currentSideStats.rework_in_progress ?? 0) + (currentSideStats.parts_in_rework ?? 0)) > 0)) return false;
                      if (reworkSubTab === 'revert' && !((currentSideStats.total_revertible ?? 0) > 0)) return false;
                    }

                    // Paint subtabs
                    if (activeTab === 'paint') {
                      if (paintSubTab === 'queue' && !((currentSideStats.paint_ready ?? 0) > 0)) return false;
                      if (paintSubTab === 'revert' && !((currentSideStats.total_revertible ?? 0) > 0)) return false;
                    }

                    // Assembly subtabs
                    if (activeTab === 'assembly') {
                      if (assemblySubTab === 'queue' && !((currentSideStats.assembly_ready ?? 0) > 0)) return false;
                      if (assemblySubTab === 'completed' && !((currentSideStats.assembly_completed ?? 0) > 0)) return false;
                      if (assemblySubTab === 'revert' && !((currentSideStats.total_revertible ?? 0) > 0)) return false;
                    }

                    if (!currentSearchQuery) return true;
                    const q = currentSearchQuery.toLowerCase().trim();
                    return String(item.standard_part_no || '').toLowerCase().includes(q) ||
                           String(item.item_no || '').toLowerCase().includes(q) ||
                           String(item.supplier?.name || item.supplier_name_raw || '').toLowerCase().includes(q);
                  });

                  const getEligibleQty = (p) => {
                    const s = unitSideTab === 'LH' ? (p.side_stats?.LH || p.side_stats?.COMMON || {}) : (p.side_stats?.RH || p.side_stats?.COMMON || {});
                    if (activeTab === 'store') {
                      return storeSubTab === 'revert' ? (s.total_revertible || 0) : (s.pending || 0);
                    }
                    if (activeTab === 'qc') {
                      if (qcSubTab === 'arrival') return s.qc_pending_arrival || 0;
                      if (qcSubTab === 'inspection') return s.qc_pending_inspection || 0;
                      if (qcSubTab === 'revert') return s.total_revertible || 0;
                    }
                    if (activeTab === 'rework') {
                      return reworkSubTab === 'revert' ? (s.total_revertible || 0) : ((s.parts_in_rework || 0) || ((s.rework_pending || 0) + (s.rework_in_progress || 0)));
                    }
                    if (activeTab === 'paint') {
                      return paintSubTab === 'revert' ? (s.total_revertible || 0) : (s.paint_ready || 0);
                    }
                    if (activeTab === 'assembly') {
                      return assemblySubTab === 'revert' ? (s.total_revertible || 0) : (s.assembly_ready || 0);
                    }
                    return 1;
                  };

                  const selectedItemsList = visibleParts.filter(p => selectedItemIds.has(`${p.id}_${unitSideTab}`));
                  const selectedPartsCount = selectedItemsList.length;
                  const selectedTotalQuantity = selectedItemsList.reduce((sum, item) => sum + getEligibleQty(item), 0);

                  const isCurrentRevertTab = (activeTab === 'store' && storeSubTab === 'revert') ||
                                             (activeTab === 'qc' && qcSubTab === 'revert') ||
                                             (activeTab === 'rework' && reworkSubTab === 'revert') ||
                                             (activeTab === 'paint' && paintSubTab === 'revert') ||
                                             (activeTab === 'assembly' && assemblySubTab === 'revert');

                  return (
                    <View>
                      {/* Department Subtab Switchers */}
                      {activeTab === 'store' && (() => {
                        const getSideStat = (p) => (unitSideTab === 'LH' ? (p.side_stats?.LH || p.side_stats?.COMMON) : (p.side_stats?.RH || p.side_stats?.COMMON));
                        const pendingCount = (selectedUnit.parts || []).filter(p => (getSideStat(p)?.pending || 0) > 0).length;
                        const revertCount = (selectedUnit.parts || []).filter(p => (getSideStat(p)?.total_revertible || 0) > 0).length;

                        return (
                          <View style={styles.deptSubtabRow}>
                            <TouchableOpacity
                              style={[styles.deptSubtabBtn, storeSubTab === 'pending' && styles.deptSubtabBtnActive]}
                              onPress={() => { setStoreSubTab('pending'); clearSelection(); }}>
                              <Text style={[styles.deptSubtabBtnText, storeSubTab === 'pending' && styles.deptSubtabBtnTextActive]}>
                                📦 Pending Intake ({pendingCount})
                              </Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                              style={[styles.deptSubtabBtn, storeSubTab === 'revert' && styles.deptSubtabBtnActiveRevert]}
                              onPress={() => { setStoreSubTab('revert'); clearSelection(); }}>
                              <Text style={[styles.deptSubtabBtnText, storeSubTab === 'revert' && styles.deptSubtabBtnTextActiveRevert]}>
                                ↩ Revert ({revertCount})
                              </Text>
                            </TouchableOpacity>
                          </View>
                        );
                      })()}

                      {activeTab === 'qc' && (() => {
                        const getSideStat = (p) => (unitSideTab === 'LH' ? (p.side_stats?.LH || p.side_stats?.COMMON) : (p.side_stats?.RH || p.side_stats?.COMMON));
                        const arrivalCount = (selectedUnit.parts || []).filter(p => (getSideStat(p)?.qc_pending_arrival || 0) > 0).length;
                        const inspectionCount = (selectedUnit.parts || []).filter(p => (getSideStat(p)?.qc_pending_inspection || 0) > 0).length;
                        const revertCount = (selectedUnit.parts || []).filter(p => (getSideStat(p)?.total_revertible || 0) > 0).length;

                        return (
                          <View style={styles.deptSubtabRow}>
                            <TouchableOpacity
                              style={[styles.deptSubtabBtn, qcSubTab === 'arrival' && styles.qcModeBtnActiveArrival]}
                              onPress={() => { setQcSubTab('arrival'); clearSelection(); }}>
                              <Text style={[styles.deptSubtabBtnText, qcSubTab === 'arrival' && styles.qcModeBtnTextActiveArrival]}>
                                📦 1. Arrival ({arrivalCount})
                              </Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                              style={[styles.deptSubtabBtn, qcSubTab === 'inspection' && styles.qcModeBtnActiveInspection]}
                              onPress={() => { setQcSubTab('inspection'); clearSelection(); }}>
                              <Text style={[styles.deptSubtabBtnText, qcSubTab === 'inspection' && styles.qcModeBtnTextActiveInspection]}>
                                🔬 2. Inspection ({inspectionCount})
                              </Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                              style={[styles.deptSubtabBtn, qcSubTab === 'revert' && styles.deptSubtabBtnActiveRevert]}
                              onPress={() => { setQcSubTab('revert'); clearSelection(); }}>
                              <Text style={[styles.deptSubtabBtnText, qcSubTab === 'revert' && styles.deptSubtabBtnTextActiveRevert]}>
                                ↩ Revert ({revertCount})
                              </Text>
                            </TouchableOpacity>
                          </View>
                        );
                      })()}

                      {activeTab === 'rework' && (() => {
                        const getSideStat = (p) => (unitSideTab === 'LH' ? (p.side_stats?.LH || p.side_stats?.COMMON) : (p.side_stats?.RH || p.side_stats?.COMMON));
                        const queueCount = (selectedUnit.parts || []).filter(p => ((getSideStat(p)?.rework_pending || 0) + (getSideStat(p)?.rework_in_progress || 0)) > 0).length;
                        const revertCount = (selectedUnit.parts || []).filter(p => (getSideStat(p)?.total_revertible || 0) > 0).length;

                        return (
                          <View style={styles.deptSubtabRow}>
                            <TouchableOpacity
                              style={[styles.deptSubtabBtn, reworkSubTab === 'queue' && styles.deptSubtabBtnActiveRework]}
                              onPress={() => { setReworkSubTab('queue'); clearSelection(); }}>
                              <Text style={[styles.deptSubtabBtnText, reworkSubTab === 'queue' && styles.deptSubtabBtnTextActiveRework]}>
                                🛠️ Rework Queue ({queueCount})
                              </Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                              style={[styles.deptSubtabBtn, reworkSubTab === 'revert' && styles.deptSubtabBtnActiveRevert]}
                              onPress={() => { setReworkSubTab('revert'); clearSelection(); }}>
                              <Text style={[styles.deptSubtabBtnText, reworkSubTab === 'revert' && styles.deptSubtabBtnTextActiveRevert]}>
                                ↩ Revert ({revertCount})
                              </Text>
                            </TouchableOpacity>
                          </View>
                        );
                      })()}

                      {activeTab === 'paint' && (() => {
                        const getSideStat = (p) => (unitSideTab === 'LH' ? (p.side_stats?.LH || p.side_stats?.COMMON) : (p.side_stats?.RH || p.side_stats?.COMMON));
                        const queueCount = (selectedUnit.parts || []).filter(p => (getSideStat(p)?.paint_ready || 0) > 0).length;
                        const revertCount = (selectedUnit.parts || []).filter(p => (getSideStat(p)?.total_revertible || 0) > 0).length;

                        return (
                          <View style={styles.deptSubtabRow}>
                            <TouchableOpacity
                              style={[styles.deptSubtabBtn, paintSubTab === 'queue' && styles.deptSubtabBtnActivePaint]}
                              onPress={() => { setPaintSubTab('queue'); clearSelection(); }}>
                              <Text style={[styles.deptSubtabBtnText, paintSubTab === 'queue' && styles.deptSubtabBtnTextActivePaint]}>
                                🎨 Paint Queue ({queueCount})
                              </Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                              style={[styles.deptSubtabBtn, paintSubTab === 'revert' && styles.deptSubtabBtnActiveRevert]}
                              onPress={() => { setPaintSubTab('revert'); clearSelection(); }}>
                              <Text style={[styles.deptSubtabBtnText, paintSubTab === 'revert' && styles.deptSubtabBtnTextActiveRevert]}>
                                ↩ Revert ({revertCount})
                              </Text>
                            </TouchableOpacity>
                          </View>
                        );
                      })()}

                      {activeTab === 'assembly' && (() => {
                        const getSideStat = (p) => (unitSideTab === 'LH' ? (p.side_stats?.LH || p.side_stats?.COMMON) : (p.side_stats?.RH || p.side_stats?.COMMON));
                        const queueCount = (selectedUnit.parts || []).filter(p => (getSideStat(p)?.assembly_ready || 0) > 0).length;
                        const completedCount = (selectedUnit.parts || []).filter(p => (getSideStat(p)?.assembly_completed || 0) > 0).length;
                        const revertCount = (selectedUnit.parts || []).filter(p => (getSideStat(p)?.total_revertible || 0) > 0).length;

                        return (
                          <View style={styles.deptSubtabRow}>
                            <TouchableOpacity
                              style={[styles.deptSubtabBtn, assemblySubTab === 'queue' && styles.deptSubtabBtnActiveAssembly]}
                              onPress={() => { setAssemblySubTab('queue'); clearSelection(); }}>
                              <Text style={[styles.deptSubtabBtnText, assemblySubTab === 'queue' && styles.deptSubtabBtnTextActiveAssembly]}>
                                ⚙️ Queue ({queueCount})
                              </Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                              style={[styles.deptSubtabBtn, assemblySubTab === 'completed' && styles.deptSubtabBtnActiveCompleted]}
                              onPress={() => { setAssemblySubTab('completed'); clearSelection(); }}>
                              <Text style={[styles.deptSubtabBtnText, assemblySubTab === 'completed' && styles.deptSubtabBtnTextActiveCompleted]}>
                                🏁 Done ({completedCount})
                              </Text>
                            </TouchableOpacity>
                            <TouchableOpacity
                              style={[styles.deptSubtabBtn, assemblySubTab === 'revert' && styles.deptSubtabBtnActiveRevert]}
                              onPress={() => { setAssemblySubTab('revert'); clearSelection(); }}>
                              <Text style={[styles.deptSubtabBtnText, assemblySubTab === 'revert' && styles.deptSubtabBtnTextActiveRevert]}>
                                ↩ Revert ({revertCount})
                              </Text>
                            </TouchableOpacity>
                          </View>
                        );
                      })()}

                      {/* Multi-Selection Control Bar */}
                      {!isCurrentRevertTab && (
                        <View style={styles.selectionControlBar}>
                          <TouchableOpacity
                            style={styles.selectionToggleBtn}
                            onPress={() => {
                              if (isSelectionMode) {
                                clearSelection();
                              } else {
                                setIsSelectionMode(true);
                              }
                            }}>
                            <Text style={styles.selectionToggleText}>
                              {isSelectionMode ? '✕ Cancel Selection' : '☑ Multi-Select'}
                            </Text>
                          </TouchableOpacity>

                          {isSelectionMode && (
                            <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6 }}>
                              <TouchableOpacity
                                style={styles.selectAllBtn}
                                onPress={() => selectAllVisible(visibleParts, unitSideTab)}>
                                <Text style={styles.selectAllBtnText}>Select All ({visibleParts.length})</Text>
                              </TouchableOpacity>
                              {selectedItemIds.size > 0 && (
                                <TouchableOpacity style={styles.clearSelectBtn} onPress={clearSelection}>
                                  <Text style={styles.clearSelectBtnText}>Clear ({selectedItemIds.size})</Text>
                                </TouchableOpacity>
                              )}
                            </View>
                          )}
                        </View>
                      )}

                      {/* Parts Cards (High-Density Industrial Layout) */}
                      {visibleParts.map((item) => {
                        if (!item) return null;
                        const itemKey = `${item.id}_${unitSideTab}`;
                        const isSelected = selectedItemIds.has(itemKey);
                        const currentSideStats = unitSideTab === 'LH' ? (item.side_stats?.LH || item.side_stats?.COMMON || {}) : (item.side_stats?.RH || item.side_stats?.COMMON || {});
                        const req = Number(currentSideStats.required ?? 0);
                        const rec = Number(currentSideStats.received ?? 0);
                        const pen = Number(currentSideStats.pending ?? 0);
                        const revertOpts = Array.isArray(currentSideStats.revert_options) ? currentSideStats.revert_options : [];
                        const totalRevertible = Number(currentSideStats.total_revertible ?? 0);

                        {/* COMPACT REVERT CARD (High-Density Space-Efficient Layout) */}
                        if (isCurrentRevertTab) {
                          return (
                            <View
                              key={`part-rev-${item.id}-side-${unitSideTab}`}
                              style={[
                                styles.compactRevertCard,
                                item.is_ecn && { borderColor: '#f59e0b', backgroundColor: '#fffdf5' }
                              ]}>
                              {/* Row 1: Part No + ECN Badge + Side Pill + Reversible Badge */}
                              <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
                                <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6, flex: 1 }}>
                                  {item.is_ecn && (
                                    <View style={styles.ecnBadge}>
                                      <Text style={styles.ecnBadgeText}>⚡ {item.ecn_number || 'ECN'}</Text>
                                    </View>
                                  )}
                                  <Text style={styles.itemPartNo} numberOfLines={1}>{item.standard_part_no}</Text>
                                  <View style={unitSideTab === 'LH' ? styles.sidePillLh : styles.sidePillRh}>
                                    <Text style={unitSideTab === 'LH' ? styles.sidePillTextLh : styles.sidePillTextRh}>
                                      {unitSideTab}{item.original_side && item.original_side !== unitSideTab ? ` (${item.original_side})` : ''}
                                    </Text>
                                  </View>
                                </View>
                                <Text style={styles.compactRevertStatusBadge}>
                                  {totalRevertible} REVERSIBLE
                                </Text>
                              </View>

                              {/* Row 2: Lineage Info & Compact Revert Action Button */}
                              {revertOpts.length > 1 ? (
                                <View style={{ marginTop: 6, gap: 5 }}>
                                  {revertOpts.map((opt, idx) => (
                                    <View key={`rev-seg-${idx}`} style={styles.compactRevertSegmentRow}>
                                      <View style={{ flex: 1, marginRight: 8 }}>
                                        <Text style={styles.compactRevertInfoText}>
                                          Revert Qty: <Text style={{ fontWeight: '800', color: '#c2410c' }}>{opt.available_quantity} pcs</Text>  •  To: <Text style={{ fontWeight: '700', color: '#0f172a' }}>{opt.target_label || opt.to_department}</Text>
                                        </Text>
                                        {opt.description ? (
                                          <Text style={styles.compactRevertLineageSubtext}>
                                            Lineage: {opt.description}
                                          </Text>
                                        ) : null}
                                      </View>
                                      <TouchableOpacity
                                        style={styles.compactRevertActionBtn}
                                        onPress={() => openRevertModal(item, activeTab, unitSideTab, opt)}>
                                        <Text style={styles.compactRevertActionBtnText}>Revert</Text>
                                      </TouchableOpacity>
                                    </View>
                                  ))}
                                </View>
                              ) : (
                                <View style={styles.compactRevertSingleRow}>
                                  <Text style={styles.compactRevertInfoText}>
                                    Revert Qty: <Text style={{ fontWeight: '800', color: '#c2410c' }}>{totalRevertible} pcs</Text>  •  To: <Text style={{ fontWeight: '700', color: '#0f172a' }}>{revertOpts[0]?.target_label || (activeTab === 'store' ? 'Pending Supplier Arrival' : (activeTab === 'qc' ? 'Store Bay' : 'Quality Control Bay'))}</Text>
                                  </Text>
                                  <TouchableOpacity
                                    style={styles.compactRevertActionBtn}
                                    onPress={() => openRevertModal(item, activeTab, unitSideTab, revertOpts[0])}>
                                    <Text style={styles.compactRevertActionBtnText}>Revert</Text>
                                  </TouchableOpacity>
                                </View>
                              )}
                            </View>
                          );
                        }

                        return (
                          <TouchableOpacity
                            key={`part-${item.id}-side-${unitSideTab}`}
                            activeOpacity={0.85}
                            onLongPress={() => toggleSelection(item, unitSideTab)}
                            onPress={() => {
                              if (isSelectionMode) toggleSelection(item, unitSideTab);
                            }}
                            style={[
                              styles.itemCard,
                              item.is_ecn && { borderColor: '#f59e0b', borderWidth: 1.5, backgroundColor: '#fffdf5' },
                              isSelected && { borderColor: '#2563eb', borderWidth: 2, backgroundColor: '#eff6ff' }
                            ]}>
                            {/* Row 1: Part No + ECN Badge + Side Pill + Status Badge + Checkbox */}
                            <View style={styles.itemHeader}>
                              <View style={{ flexDirection: 'row', alignItems: 'center', gap: 6, flex: 1 }}>
                                {isSelectionMode && (
                                  <View style={[styles.checkboxCircle, isSelected && styles.checkboxCircleSelected]}>
                                    {isSelected && <Text style={styles.checkmarkText}>✓</Text>}
                                  </View>
                                )}
                                {item.is_ecn && (
                                  <View style={styles.ecnBadge}>
                                    <Text style={styles.ecnBadgeText}>⚡ {item.ecn_number || 'ECN'}</Text>
                                  </View>
                                )}
                                <Text style={styles.itemPartNo} numberOfLines={1}>{item.standard_part_no}</Text>
                                <View style={unitSideTab === 'LH' ? styles.sidePillLh : styles.sidePillRh}>
                                  <Text style={unitSideTab === 'LH' ? styles.sidePillTextLh : styles.sidePillTextRh}>
                                    {unitSideTab}{item.original_side && item.original_side !== unitSideTab ? ` (${item.original_side})` : ''}
                                  </Text>
                                </View>
                              </View>
                              <Text style={[styles.itemStatus, item.is_ecn && { color: '#b45309', backgroundColor: '#fef3c7' }]}>
                                {item.is_ecn ? (item.is_complete ? 'ECN DONE' : '⚡ ECN') : (item.is_complete ? 'FULFILLED' : 'ACTIVE')}
                              </Text>
                            </View>

                            {/* Row 2: Inline Stats & Supplier */}
                            <View style={{ marginTop: 2, marginBottom: 4 }}>
                              <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
                                <Text style={styles.itemSubText}>
                                  <Text style={{ fontWeight: '700', color: '#1e293b' }}>Req: {req}</Text> • Rec: {rec} • Pen: {pen}
                                </Text>
                                <Text style={[styles.itemSubText, { fontSize: 10, color: '#64748b', maxWidth: '45%' }]} numberOfLines={1}>
                                  {item.is_ecn ? `⚡ ECN Revision • ${item.ecn_number || ''}` : (item.supplier?.name || item.supplier_name_raw || 'Standard')}
                                </Text>
                              </View>
                            </View>

                            {/* OPERATIONAL ACTIONS (Visible ONLY in forward work queues, strictly no revert) */}
                            <View>
                                {/* Store Level 4 Single Action */}
                                {activeTab === 'store' && pen > 0 && !isSelectionMode && (
                                  <TouchableOpacity style={styles.smallReceiveBtn} onPress={() => openReceiveModal(item, unitSideTab)}>
                                    <Text style={styles.smallReceiveBtnText}>RECEIVE {unitSideTab} STOCK ({pen})</Text>
                                  </TouchableOpacity>
                                )}

                                {/* QC Actions (Separated strictly by subtab & isolated by side) */}
                                {activeTab === 'qc' && !isSelectionMode && (() => {
                                  const sideStat = item.side_stats?.[unitSideTab] || {};
                                  const pendingArrival = sideStat.qc_pending_arrival || 0;
                                  const pendingInsp = sideStat.qc_pending_inspection || 0;
                                  const sideReceipts = sideStat.receipt_items || (item.receipt_items || []).filter(r => r.side === unitSideTab || r.side === 'COMMON');

                                  return (
                                    <View style={{ marginTop: 6 }}>
                                      {qcSubTab === 'arrival' && pendingArrival > 0 ? (
                                        <View style={{ marginTop: 2 }}>
                                          <TouchableOpacity
                                            style={[styles.actionBtn, { backgroundColor: '#10b981' }]}
                                            onPress={() => openPhysicalArrivalModal(item)}>
                                            <Text style={styles.actionBtnText}>RECEIVE PHYSICAL ARRIVAL ({pendingArrival} pcs)</Text>
                                          </TouchableOpacity>
                                        </View>
                                      ) : null}

                                      {qcSubTab === 'inspection' && pendingInsp > 0 ? (
                                        <View style={{ marginTop: 2, gap: 4 }}>
                                          <View style={{ flexDirection: 'row', gap: 6 }}>
                                            <TouchableOpacity
                                              style={[styles.actionBtn, { flex: 1, backgroundColor: '#10b981' }]}
                                              onPress={() => {
                                                const rec = sideReceipts.find(r => r.status === 'qc_received') || {
                                                  id: null,
                                                  bom_item_id: item.id,
                                                  received_quantity: pendingInsp,
                                                  bom_item: item,
                                                  side: unitSideTab
                                                };
                                                openQcModal({ ...rec, bom_item_id: item.id, bom_item: item, side: unitSideTab, received_quantity: pendingInsp }, 'approved');
                                              }}>
                                              <Text style={styles.actionBtnText}>APPROVE ({pendingInsp})</Text>
                                            </TouchableOpacity>

                                            <TouchableOpacity
                                              style={[styles.actionBtn, { flex: 1, backgroundColor: '#f59e0b' }]}
                                              onPress={() => {
                                                const rec = sideReceipts.find(r => r.status === 'qc_received') || {
                                                  id: null,
                                                  bom_item_id: item.id,
                                                  received_quantity: pendingInsp,
                                                  bom_item: item,
                                                  side: unitSideTab
                                                };
                                                openQcModal({ ...rec, bom_item_id: item.id, bom_item: item, side: unitSideTab, received_quantity: pendingInsp }, 'rework');
                                              }}>
                                              <Text style={styles.actionBtnText}>REWORK ({pendingInsp})</Text>
                                            </TouchableOpacity>

                                            <TouchableOpacity
                                              style={[styles.actionBtn, { flex: 1, backgroundColor: '#ef4444' }]}
                                              onPress={() => {
                                                const rec = sideReceipts.find(r => r.status === 'qc_received') || {
                                                  id: null,
                                                  bom_item_id: item.id,
                                                  received_quantity: pendingInsp,
                                                  bom_item: item,
                                                  side: unitSideTab
                                                };
                                                openQcModal({ ...rec, bom_item_id: item.id, bom_item: item, side: unitSideTab, received_quantity: pendingInsp }, 'rejected');
                                              }}>
                                              <Text style={styles.actionBtnText}>REJECT ({pendingInsp})</Text>
                                            </TouchableOpacity>
                                          </View>
                                        </View>
                                      ) : null}
                                    </View>
                                  );
                                })()}

                                {/* Rework Actions (Strictly side-isolated, Single Action: COMPLETE REWORK) */}
                                {activeTab === 'rework' && !isSelectionMode && (() => {
                                  const sideStat = item.side_stats?.[unitSideTab] || {};
                                  const rewPending = sideStat.rework_pending || 0;
                                  const rewProg = sideStat.rework_in_progress || 0;
                                  const rewActive = (sideStat.parts_in_rework || 0) || (rewPending + rewProg);
                                  const rewComp = sideStat.rework_completed || 0;
                                  const sideReworks = sideStat.rework_records || (item.rework_records || []).filter(r => r.side === unitSideTab || r.side === 'COMMON');

                                  return (
                                    <View style={{ marginTop: 6 }}>
                                      {rewActive > 0 ? (
                                        <TouchableOpacity
                                          style={[styles.actionBtn, { backgroundColor: '#f59e0b' }]}
                                          onPress={() => {
                                            const rew = sideReworks.find(r => ['pending', 'in_progress'].includes(r.status)) || {
                                              id: null,
                                              quantity: rewActive,
                                              bom_item_id: item.id,
                                              side: unitSideTab,
                                            };
                                            openReworkModal({ ...rew, bom_item_id: item.id, quantity: rewActive }, item);
                                          }}>
                                          <Text style={styles.actionBtnText}>COMPLETE REWORK ({rewActive} pcs)</Text>
                                        </TouchableOpacity>
                                      ) : (
                                        <Text style={{ fontSize: 11, color: '#64748b', marginTop: 3 }}>
                                          {rewComp > 0 ? `✓ ${rewComp} pcs Rework Completed (Returned to QC)` : '✓ No Active Rework'}
                                        </Text>
                                      )}
                                    </View>
                                  );
                                })()}

                                {/* Paint Actions (Strictly side-isolated) */}
                                {activeTab === 'paint' && !isSelectionMode && (() => {
                                  const sideStat = item.side_stats?.[unitSideTab] || {};
                                  const paintReady = sideStat.paint_ready || 0;
                                  const paintComp = sideStat.paint_completed || 0;
                                  const sideInspections = sideStat.qc_inspections || (item.qc_inspections || []).filter(q => q.side === unitSideTab || q.side === 'COMMON');
                                  const insp = sideInspections.find(q => q.approved_quantity > 0 && (q.destination === 'PAINT' || !q.destination));

                                  return (
                                    <View style={{ marginTop: 6 }}>
                                      {paintReady > 0 && insp ? (
                                        <TouchableOpacity
                                          style={[styles.actionBtn, { backgroundColor: '#7c3aed' }]}
                                          onPress={() => {
                                            openPaintModal({
                                              ...insp,
                                              bom_item_id: item.id,
                                              bom_item: item,
                                              side: unitSideTab,
                                              approved_quantity: paintReady,
                                            });
                                          }}>
                                          <Text style={styles.actionBtnText}>COMPLETE PAINT ({paintReady} pcs)</Text>
                                        </TouchableOpacity>
                                      ) : (
                                        <Text style={{ fontSize: 11, color: '#7c3aed', fontWeight: '700', marginTop: 3 }}>
                                          {paintComp > 0 ? `✓ ${paintComp} pcs Painted` : '✓ No Paint Operations Pending'}
                                        </Text>
                                      )}
                                    </View>
                                  );
                                })()}

                                {/* Assembly Actions (Strictly side-isolated) */}
                                {activeTab === 'assembly' && !isSelectionMode && (() => {
                                  const sideStat = item.side_stats?.[unitSideTab] || {};
                                  const asmReady = sideStat.assembly_ready || 0;
                                  const asmComp = sideStat.assembly_completed || 0;

                                  return (
                                    <View style={{ marginTop: 6 }}>
                                      {asmReady > 0 ? (
                                        <TouchableOpacity
                                          style={[styles.actionBtn, { backgroundColor: '#0d9488' }]}
                                          onPress={() => openAssemblyModal(item)}>
                                          <Text style={styles.actionBtnText}>MARK ASSEMBLED ({asmReady} pcs)</Text>
                                        </TouchableOpacity>
                                      ) : (
                                        <Text style={{ fontSize: 11, color: '#0d9488', fontWeight: '700', marginTop: 3 }}>
                                          {asmComp > 0 ? `✓ ${asmComp} pcs Assembled` : '✓ No Assembly Operations Pending'}
                                        </Text>
                                      )}
                                    </View>
                                  );
                                })()}
                              </View>
                            </TouchableOpacity>
                          );
                        })}

                      {visibleParts.length === 0 && (
                        <View style={[styles.emptyState, { paddingVertical: 24, alignItems: 'center' }]}>
                          {activeTab === 'qc' && qcSubTab === 'inspection' ? (
                            <>
                              <Text style={[styles.emptyStateText, { fontWeight: '700', fontSize: 15, color: '#1e293b', marginBottom: 4, textAlign: 'center' }]}>
                                No Parts Ready for Quality Inspection
                              </Text>
                              <Text style={[styles.emptyStateText, { color: '#64748b', fontSize: 12, textAlign: 'center' }]}>
                                Parts will appear here after Physical Arrival is completed.
                              </Text>
                            </>
                          ) : activeTab === 'qc' && qcSubTab === 'arrival' ? (
                            <>
                              <Text style={[styles.emptyStateText, { fontWeight: '700', fontSize: 15, color: '#1e293b', marginBottom: 4, textAlign: 'center' }]}>
                                No Pending Physical Arrivals
                              </Text>
                              <Text style={[styles.emptyStateText, { color: '#64748b', fontSize: 12, textAlign: 'center' }]}>
                                All parts for this unit have completed physical arrival.
                              </Text>
                            </>
                          ) : isCurrentRevertTab ? (
                            <>
                              <Text style={[styles.emptyStateText, { fontWeight: '700', fontSize: 15, color: '#1e293b', marginBottom: 4, textAlign: 'center' }]}>
                                No Reversible Parts in {activeTab.toUpperCase()}
                              </Text>
                              <Text style={[styles.emptyStateText, { color: '#64748b', fontSize: 12, textAlign: 'center' }]}>
                                No parts currently have eligible reverse lineage in this unit.
                              </Text>
                            </>
                          ) : (
                            <Text style={styles.emptyStateText}>No active {unitSideTab} parts found for this unit{currentSearchQuery ? ` matching "${currentSearchQuery}"` : ''}.</Text>
                          )}
                          <TouchableOpacity
                            style={[styles.smallReceiveBtn, { marginTop: 14, backgroundColor: '#0284c7', paddingHorizontal: 18, paddingVertical: 8 }]}
                            onPress={() => {
                              setSelectedUnit(null);
                              clearSelection();
                            }}>
                            <Text style={styles.smallReceiveBtnText}>‹ Back to Units List</Text>
                          </TouchableOpacity>
                        </View>
                      )}
                    </View>
                  );
                })()}
              </View>
            )}
              </>
            )}
          </View>
        ) : (
          // PURCHASE QUEUE OR FALLBACK
          <View style={styles.listContainer}>
            <Text style={styles.sectionHeader}>
              PURCHASE REQUISITION QUEUE ({
                items.filter(item => {
                  if (!currentSearchQuery) return true;
                  const q = currentSearchQuery.toLowerCase().trim();
                  return (item.bom_item?.standard_part_no || '').toLowerCase().includes(q) ||
                         (item.bom_item?.project?.name || '').toLowerCase().includes(q) ||
                         (item.side || '').toLowerCase().includes(q) ||
                         (item.reason || '').toLowerCase().includes(q) ||
                         (item.status || '').toLowerCase().includes(q);
                }).length
              })
            </Text>
            {items
              .filter(item => {
                if (!currentSearchQuery) return true;
                const q = currentSearchQuery.toLowerCase().trim();
                return (item.bom_item?.standard_part_no || '').toLowerCase().includes(q) ||
                       (item.bom_item?.project?.name || '').toLowerCase().includes(q) ||
                       (item.side || '').toLowerCase().includes(q) ||
                       (item.reason || '').toLowerCase().includes(q) ||
                       (item.status || '').toLowerCase().includes(q);
              })
              .map((item, idx) => (
              <View key={item.id || idx} style={styles.itemCard}>
                <View style={styles.itemHeader}>
                  <Text style={styles.itemPartNo}>{item.bom_item?.standard_part_no || `Item #${item.id}`}</Text>
                  <Text style={styles.itemStatus}>{(item.status || 'PENDING').toUpperCase()}</Text>
                </View>
                <Text style={styles.itemSubText}>Project: {item.bom_item?.project?.name || 'N/A'}</Text>
                <Text style={styles.itemSubText}>Side: {item.side} | Qty Required: {item.quantity}</Text>
                <Text style={styles.itemSubText}>Reason: {item.reason || 'QC Rejection'}</Text>
              </View>
            ))}
            {items.filter(item => {
              if (!currentSearchQuery) return true;
              const q = currentSearchQuery.toLowerCase().trim();
              return (item.bom_item?.standard_part_no || '').toLowerCase().includes(q) ||
                     (item.bom_item?.project?.name || '').toLowerCase().includes(q) ||
                     (item.side || '').toLowerCase().includes(q) ||
                     (item.reason || '').toLowerCase().includes(q) ||
                     (item.status || '').toLowerCase().includes(q);
            }).length === 0 && (
              <View style={styles.emptyState}>
                <Text style={styles.emptyStateText}>No purchase items found{currentSearchQuery ? ` matching "${currentSearchQuery}"` : ''}.</Text>
              </View>
            )}
          </View>
        )}
      </ScrollView>

      {/* GLOBAL REVERT FIXED BOTTOM STICKY ACTION BAR */}
      {isTopLevelRevertTab && isGlobalRevertSelectionMode && selectedGlobalRevertIds.size > 0 && (() => {
        const selectedList = globalRevertItems.filter(i => selectedGlobalRevertIds.has(i.id));
        const totalSelectedQty = selectedList.reduce((sum, item) => sum + (item.available_quantity || 0), 0);

        return (
          <View style={styles.stickyBottomActionBar}>
            <View style={styles.stickyBarHeader}>
              <Text style={styles.stickyBarCountBadge}>
                ↩ Selected: {selectedGlobalRevertIds.size} parts • Total: {totalSelectedQty} pcs
              </Text>
              <TouchableOpacity onPress={() => setSelectedGlobalRevertIds(new Set())} style={styles.stickyBarClearBtn}>
                <Text style={styles.stickyBarClearText}>✕ Clear</Text>
              </TouchableOpacity>
            </View>

            <TouchableOpacity
              style={[styles.bulkBtn, { backgroundColor: '#dc2626' }]}
              disabled={isSubmittingGlobalRevert}
              onPress={() => setShowBulkGlobalRevertModal(true)}>
              <Text style={styles.bulkBtnText}>
                ↩ BULK REVERT SELECTED ({selectedGlobalRevertIds.size} PARTS • {totalSelectedQty} PCS)
              </Text>
            </TouchableOpacity>
          </View>
        );
      })()}

      {/* FIXED BOTTOM STICKY ACTION BAR */}
      {selectedItemIds.size > 0 && selectedUnit && (() => {
        const selectedItemsList = (selectedUnit?.parts || []).filter(p => selectedItemIds.has(`${p.id}_${unitSideTab}`));
        const getEligibleQty = (p) => {
          const s = unitSideTab === 'LH' ? (p.side_stats?.LH || p.side_stats?.COMMON || {}) : (p.side_stats?.RH || p.side_stats?.COMMON || {});
          if (activeTab === 'store') return storeSubTab === 'revert' ? (s.total_revertible || 0) : (s.pending || 0);
          if (activeTab === 'qc') {
            if (qcSubTab === 'arrival') return s.qc_pending_arrival || 0;
            if (qcSubTab === 'inspection') return s.qc_pending_inspection || 0;
            if (qcSubTab === 'revert') return s.total_revertible || 0;
          }
          if (activeTab === 'rework') return reworkSubTab === 'revert' ? (s.total_revertible || 0) : ((s.parts_in_rework || 0) || ((s.rework_pending || 0) + (s.rework_in_progress || 0)));
          if (activeTab === 'paint') return paintSubTab === 'revert' ? (s.total_revertible || 0) : (s.paint_ready || 0);
          if (activeTab === 'assembly') return assemblySubTab === 'revert' ? (s.total_revertible || 0) : (s.assembly_ready || 0);
          return 1;
        };
        const totalSelectedQty = selectedItemsList.reduce((sum, item) => sum + getEligibleQty(item), 0);

        return (
          <View style={styles.stickyBottomActionBar}>
            <View style={styles.stickyBarHeader}>
              <Text style={styles.stickyBarCountBadge}>
                Selected: {selectedItemIds.size} {selectedItemIds.size === 1 ? 'part' : 'parts'} ({unitSideTab})   •   Total: {totalSelectedQty} pcs
              </Text>
              <TouchableOpacity onPress={clearSelection} style={styles.stickyBarClearBtn}>
                <Text style={styles.stickyBarClearText}>✕ Clear</Text>
              </TouchableOpacity>
            </View>

            {/* Department-specific primary bulk action controls */}
            <View>
                {activeTab === 'store' && storeSubTab === 'pending' && (
                  <TouchableOpacity
                    style={[styles.bulkBtn, { backgroundColor: '#2563eb' }]}
                    onPress={() => {
                      setBulkDeliveryNote(`DN-${new Date().toISOString().slice(0, 10)}`);
                      setShowBulkStoreReceiveModal(true);
                    }}>
                    <Text style={styles.bulkBtnText}>RECEIVE SELECTED ({selectedItemIds.size} PARTS)</Text>
                  </TouchableOpacity>
                )}

                {activeTab === 'store' && storeSubTab === 'revert' && (
                  <TouchableOpacity
                    style={[styles.bulkBtn, { backgroundColor: '#ea580c' }]}
                    onPress={() => handleBulkRevert(selectedItemsList, 'store')}>
                    <Text style={styles.bulkBtnText}>REVERT SELECTED TO SUPPLIER ({selectedItemIds.size} PARTS)</Text>
                  </TouchableOpacity>
                )}

                {activeTab === 'qc' && qcSubTab === 'arrival' && (
                  <TouchableOpacity
                    style={[styles.bulkBtn, { backgroundColor: '#10b981' }]}
                    onPress={() => handleBulkQcArrivalAccept(selectedItemsList)}>
                    <Text style={styles.bulkBtnText}>CONFIRM ARRIVAL ({selectedItemIds.size})</Text>
                  </TouchableOpacity>
                )}

                {activeTab === 'qc' && qcSubTab === 'inspection' && (
                  <View style={{ flexDirection: 'row', gap: 6 }}>
                    <TouchableOpacity
                      style={[styles.bulkBtn, { backgroundColor: '#10b981', flex: 1 }]}
                      onPress={() => setShowBulkQcDestinationModal(true)}>
                      <Text style={styles.bulkBtnText}>APPROVE ({selectedItemIds.size})</Text>
                    </TouchableOpacity>
                    <TouchableOpacity
                      style={[styles.bulkBtn, { backgroundColor: '#f59e0b', flex: 1 }]}
                      onPress={() => handleBulkQcInspect(selectedItemsList, 'rework')}>
                      <Text style={styles.bulkBtnText}>REWORK ({selectedItemIds.size})</Text>
                    </TouchableOpacity>
                    <TouchableOpacity
                      style={[styles.bulkBtn, { backgroundColor: '#ef4444', flex: 1 }]}
                      onPress={() => handleBulkQcInspect(selectedItemsList, 'rejected')}>
                      <Text style={styles.bulkBtnText}>REJECT ({selectedItemIds.size})</Text>
                    </TouchableOpacity>
                  </View>
                )}

                {activeTab === 'qc' && qcSubTab === 'revert' && (
                  <TouchableOpacity
                    style={[styles.bulkBtn, { backgroundColor: '#ea580c' }]}
                    onPress={() => handleBulkRevert(selectedItemsList, 'qc')}>
                    <Text style={styles.bulkBtnText}>REVERT SELECTED TO STORE ({selectedItemIds.size} PARTS)</Text>
                  </TouchableOpacity>
                )}

                {activeTab === 'rework' && reworkSubTab === 'queue' && (
                  <TouchableOpacity
                    style={[styles.bulkBtn, { backgroundColor: '#f59e0b' }]}
                    onPress={() => setShowBulkReworkModal(true)}>
                    <Text style={styles.bulkBtnText}>COMPLETE REWORK ({selectedItemIds.size} PARTS)</Text>
                  </TouchableOpacity>
                )}

                {activeTab === 'rework' && reworkSubTab === 'revert' && (
                  <TouchableOpacity
                    style={[styles.bulkBtn, { backgroundColor: '#ea580c' }]}
                    onPress={() => handleBulkRevert(selectedItemsList, 'rework')}>
                    <Text style={styles.bulkBtnText}>REVERT SELECTED TO QC ({selectedItemIds.size} PARTS)</Text>
                  </TouchableOpacity>
                )}

                {activeTab === 'paint' && paintSubTab === 'queue' && (
                  <TouchableOpacity
                    style={[styles.bulkBtn, { backgroundColor: '#7c3aed' }]}
                    onPress={() => setShowBulkPaintModal(true)}>
                    <Text style={styles.bulkBtnText}>COMPLETE PAINT ({selectedItemIds.size} PARTS)</Text>
                  </TouchableOpacity>
                )}

                {activeTab === 'paint' && paintSubTab === 'revert' && (
                  <TouchableOpacity
                    style={[styles.bulkBtn, { backgroundColor: '#ea580c' }]}
                    onPress={() => handleBulkRevert(selectedItemsList, 'paint')}>
                    <Text style={styles.bulkBtnText}>REVERT SELECTED TO QC ({selectedItemIds.size} PARTS)</Text>
                  </TouchableOpacity>
                )}

                {activeTab === 'assembly' && assemblySubTab === 'queue' && (
                  <TouchableOpacity
                    style={[styles.bulkBtn, { backgroundColor: '#0d9488' }]}
                    onPress={() => handleBulkAssemblyComplete(selectedItemsList)}>
                    <Text style={styles.bulkBtnText}>MARK ASSEMBLED ({selectedItemIds.size} PARTS)</Text>
                  </TouchableOpacity>
                )}

                {activeTab === 'assembly' && assemblySubTab === 'revert' && (
                  <TouchableOpacity
                    style={[styles.bulkBtn, { backgroundColor: '#ea580c' }]}
                    onPress={() => handleBulkRevert(selectedItemsList, 'assembly')}>
                    <Text style={styles.bulkBtnText}>REVERT SELECTED ({selectedItemIds.size} PARTS)</Text>
                  </TouchableOpacity>
                )}
              </View>
            </View>
          );
        })()}

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
                        {p.name ? (p.project_code ? `${p.name} (${p.project_code})` : p.name) : p.project_code}
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

            <View style={{ marginTop: 12 }}>
              <CompactQuantitySelector
                label="Received Quantity"
                value={receiveQty}
                onChange={setReceiveQty}
                max={selectedItemForReceive?.side_stats?.[receiveSide]?.pending || 99999}
                min={1}
                color="#2563eb"
              />
            </View>

            <View style={{ flexDirection: 'row', gap: 8, marginTop: 14 }}>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowReceiveModal(false)}>
                <Text style={styles.buttonText}>Cancel</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.button, { flex: 1, opacity: isSubmittingReceive ? 0.5 : 1 }]}
                onPress={submitStoreReceive}
                disabled={isSubmittingReceive}
              >
                <Text style={styles.buttonText}>{isSubmittingReceive ? 'Saving...' : 'Confirm Receipt'}</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>

      {/* BULK STORE RECEIVE MODAL */}
      <Modal visible={showBulkStoreReceiveModal} animationType="fade" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Bulk Stock Receipt ({selectedItemIds.size} Parts)</Text>
            <Text style={styles.itemSubText}>Side: {unitSideTab} • Automatically receives remaining pending quantities</Text>

            <Text style={[styles.label, { marginTop: 12 }]}>Delivery Note Number</Text>
            <TextInput
              style={styles.input}
              value={bulkDeliveryNote}
              onChangeText={setBulkDeliveryNote}
              placeholder="e.g. DN-2026-08-17"
            />

            <View style={{ flexDirection: 'row', gap: 8, marginTop: 14 }}>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowBulkStoreReceiveModal(false)}>
                <Text style={styles.buttonText}>Cancel</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.button, { flex: 1, backgroundColor: '#2563eb' }]}
                onPress={() => {
                  const parts = (selectedUnit?.parts || []).filter(p => selectedItemIds.has(`${p.id}_${unitSideTab}`));
                  handleBulkStoreReceive(parts);
                }}>
                <Text style={styles.buttonText}>Receive ({selectedItemIds.size})</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>

      {/* QC PHYSICAL ARRIVAL MODAL (Section 1: Strict Quantity Processing) */}
      <Modal visible={showPhysicalArrivalModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={[styles.modalBox, { maxHeight: '90%' }]}>
            <ScrollView showsVerticalScrollIndicator={false}>
              <Text style={styles.modalTitle}>Confirm QC Physical Arrival</Text>
              <Text style={styles.itemPartNo}>{selectedPhysicalArrivalItem?.standard_part_no || `Part #${selectedPhysicalArrivalItem?.id}`}</Text>
              
              {(() => {
                const sideStat = selectedPhysicalArrivalItem?.side_stats?.[unitSideTab] || {};
                const req = sideStat.required || 0;
                const rec = sideStat.qc_pending_inspection || 0;
                const pending = sideStat.qc_pending_arrival || selectedPhysicalArrivalItem?.received_quantity || 1;
                const curQty = parseInt(physicalArrivalQty, 10) || 0;

                return (
                  <View>
                    {/* Summary Info Box */}
                    <View style={[styles.qtySummaryBox, { backgroundColor: '#f0fdf4', borderColor: '#bbf7d0', marginTop: 8 }]}>
                      <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 3 }}>
                        <Text style={{ fontSize: 12, color: '#166534' }}>Required Quantity:</Text>
                        <Text style={{ fontSize: 12, fontWeight: '700', color: '#166534' }}>{req} pcs</Text>
                      </View>
                      <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginBottom: 3 }}>
                        <Text style={{ fontSize: 12, color: '#166534' }}>Already In Inspection Bay:</Text>
                        <Text style={{ fontSize: 12, fontWeight: '700', color: '#166534' }}>{rec} pcs</Text>
                      </View>
                      <View style={{ flexDirection: 'row', justifyContent: 'space-between' }}>
                        <Text style={{ fontSize: 12, color: '#15803d', fontWeight: '800' }}>Pending Physical Arrival:</Text>
                        <Text style={{ fontSize: 12, fontWeight: '900', color: '#15803d' }}>{pending} pcs</Text>
                      </View>
                    </View>

                    {/* Quantity To Receive Input with Compact Selector */}
                    <View style={{ marginTop: 12 }}>
                      <CompactQuantitySelector
                        label={`Quantity to Receive (Max ${pending})`}
                        value={physicalArrivalQty}
                        onChange={setPhysicalArrivalQty}
                        max={pending}
                        min={1}
                        color="#10b981"
                      />
                    </View>

                    {/* Action Buttons */}
                    <View style={{ flexDirection: 'row', gap: 8, marginTop: 16 }}>
                      <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowPhysicalArrivalModal(false)}>
                        <Text style={styles.buttonText}>Cancel</Text>
                      </TouchableOpacity>
                      <TouchableOpacity
                        style={[
                          styles.button,
                          { flex: 1, backgroundColor: '#10b981', opacity: (curQty <= 0 || curQty > pending) ? 0.6 : 1.0 }
                        ]}
                        disabled={curQty <= 0 || curQty > pending}
                        onPress={submitPhysicalArrival}>
                        <Text style={styles.buttonText}>Confirm Arrival ({curQty})</Text>
                      </TouchableOpacity>
                    </View>
                  </View>
                );
              })()}
            </ScrollView>
          </View>
        </View>
      </Modal>

      {/* QC INSPECTION MODAL (With Paint vs Assembly Split & Quantity Processing) */}
      <Modal visible={showQcModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={[styles.modalBox, { maxHeight: '90%' }]}>
            <ScrollView showsVerticalScrollIndicator={false}>
              <Text style={styles.modalTitle}>Record QC Inspection ({qcResult.toUpperCase()})</Text>
              <Text style={styles.itemPartNo}>{selectedQcItem?.bom_item?.standard_part_no || `Item #${selectedQcItem?.id}`}</Text>
              
              {(() => {
                const avail = selectedQcItem?.received_quantity || selectedQcItem?.quantity || 1;
                const app = parseInt(qcApprovedQty, 10) || 0;
                const paint = parseInt(qcPaintQty, 10) || 0;
                const asm = parseInt(qcAssemblyQty, 10) || 0;
                const rej = parseInt(qcRejectedQty, 10) || 0;
                const rew = parseInt(qcReworkQty, 10) || 0;
                const isSplitValid = (paint + asm) === app;

                return (
                  <View>
                    <View style={styles.qtySummaryBox}>
                      <Text style={styles.qtySummaryText}>
                        Available in QC Inspection: <Text style={{ fontWeight: '800', color: '#0284c7' }}>{avail} pcs</Text> ({selectedQcItem?.side || 'COMMON'})
                      </Text>
                      {(() => {
                        const totalReq = selectedQcItem?.bom_item?.requirements?.find(r => r.side === (selectedQcItem?.side || unitSideTab))?.required_quantity;
                        const processedElse = Math.max(0, (totalReq || avail) - avail);
                        if (processedElse > 0) {
                          return (
                            <Text style={{ fontSize: 10.5, color: '#64748b', marginTop: 2 }}>
                              ℹ️ Total Requirement: {totalReq} pcs ({processedElse} pcs already processed/routed)
                            </Text>
                          );
                        }
                        return null;
                      })()}
                    </View>

                    {/* APPROVAL QUANTITY & SPLIT ROUTING */}
                    {qcResult === 'approved' && (
                      <View style={{ marginTop: 10 }}>
                        <CompactQuantitySelector
                          label={`Approve Quantity (1 to ${avail})`}
                          value={qcApprovedQty}
                          onChange={(t) => {
                            const val = parseInt(t, 10) || 0;
                            setQcApprovedQty(t);
                            setQcPaintQty(String(val));
                            setQcAssemblyQty('0');
                          }}
                          max={avail}
                          min={1}
                          color="#10b981"
                        />

                        {/* SPLIT ROUTING CONTROLS */}
                        <View style={{ marginTop: 14, padding: 12, backgroundColor: '#f8fafc', borderRadius: 10, borderWidth: 1, borderColor: '#e2e8f0' }}>
                          <Text style={[styles.label, { color: '#334155', fontWeight: '800', marginBottom: 8 }]}>
                            Split Routing: Paint vs Assembly
                          </Text>

                          {/* Paint Quantity */}
                          <View style={{ marginBottom: 8 }}>
                            <CompactQuantitySelector
                              label="PAINT SHOP Quantity"
                              value={qcPaintQty}
                              onChange={(t) => {
                                setQcPaintQty(t);
                                const pVal = parseInt(t, 10) || 0;
                                setQcAssemblyQty(String(Math.max(0, app - pVal)));
                              }}
                              max={app}
                              min={0}
                              color="#7c3aed"
                            />
                          </View>

                          {/* Assembly Quantity */}
                          <View>
                            <CompactQuantitySelector
                              label="DIRECT ASSEMBLY Quantity"
                              value={qcAssemblyQty}
                              onChange={(t) => {
                                setQcAssemblyQty(t);
                                const aVal = parseInt(t, 10) || 0;
                                setQcPaintQty(String(Math.max(0, app - aVal)));
                              }}
                              max={app}
                              min={0}
                              color="#0d9488"
                            />
                          </View>

                          {/* Validation Status Indicator */}
                          <View style={{ marginTop: 10 }}>
                            {isSplitValid ? (
                              <View style={styles.validationSuccessBox}>
                                <Text style={styles.validationSuccessText}>
                                  ✓ Valid Split: {paint} Paint + {asm} Assembly = {app} Approved
                                </Text>
                              </View>
                            ) : (
                              <View style={styles.validationErrorBox}>
                                <Text style={styles.validationErrorText}>
                                  ⚠ Split Mismatch: Paint ({paint}) + Assembly ({asm}) = {paint + asm} (Must equal {app})
                                </Text>
                              </View>
                            )}
                          </View>
                        </View>
                      </View>
                    )}

                    {/* REJECTED QUANTITY CONTROLS */}
                    {qcResult === 'rejected' && (
                      <View style={{ marginTop: 10 }}>
                        <CompactQuantitySelector
                          label={`Reject Quantity (1 to ${avail})`}
                          value={qcRejectedQty}
                          onChange={setQcRejectedQty}
                          max={avail}
                          min={1}
                          color="#ef4444"
                        />

                        <Text style={[styles.label, { marginTop: 10 }]}>Rejection Defect Reason</Text>
                        <TextInput
                          style={styles.input}
                          value={qcReason}
                          onChangeText={setQcReason}
                          placeholder="e.g. Dimensional non-conformance, crack, porosity"
                        />
                      </View>
                    )}

                    {/* REWORK QUANTITY CONTROLS */}
                    {qcResult === 'rework' && (
                      <View style={{ marginTop: 10 }}>
                        <CompactQuantitySelector
                          label={`Rework Quantity (1 to ${avail})`}
                          value={qcReworkQty}
                          onChange={setQcReworkQty}
                          max={avail}
                          min={1}
                          color="#f59e0b"
                        />

                        <Text style={[styles.label, { marginTop: 10 }]}>Rework Instructions / Defect Reason</Text>
                        <TextInput
                          style={styles.input}
                          value={qcReason}
                          onChangeText={setQcReason}
                          placeholder="e.g. Hole re-tapping, surface de-burring required"
                        />
                      </View>
                    )}

                    <Text style={[styles.label, { marginTop: 10 }]}>Remarks / Inspection Notes</Text>
                    <TextInput
                      style={styles.input}
                      value={qcRemarks}
                      onChangeText={setQcRemarks}
                      placeholder="Optional remarks"
                    />

                    <View style={{ flexDirection: 'row', gap: 8, marginTop: 14 }}>
                      <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowQcModal(false)}>
                        <Text style={styles.buttonText}>Cancel</Text>
                      </TouchableOpacity>
                      <TouchableOpacity
                        style={[
                          styles.button,
                          {
                            flex: 1,
                            backgroundColor: qcResult === 'rejected' ? '#ef4444' : qcResult === 'rework' ? '#f59e0b' : '#10b981',
                            opacity: (qcResult === 'approved' && !isSplitValid) ? 0.6 : 1.0,
                          }
                        ]}
                        disabled={qcResult === 'approved' && !isSplitValid}
                        onPress={submitQcInspection}>
                        <Text style={styles.buttonText}>Confirm {qcResult.toUpperCase()}</Text>
                      </TouchableOpacity>
                    </View>
                  </View>
                );
              })()}
            </ScrollView>
          </View>
        </View>
      </Modal>

      {/* BULK QC APPROVAL DESTINATION MODAL (Prominent PAINT & ASSEMBLY Labels with Fixed Layout) */}
      <Modal visible={showBulkQcDestinationModal} animationType="fade" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Bulk QC Approval Route Selection</Text>
            <Text style={styles.itemSubText}>
              Select where the {selectedItemIds.size} approved parts should proceed ({unitSideTab}):
            </Text>

            <View style={{ marginTop: 14 }}>
              {/* PAINT BUTTON WITH PROMINENT TEXT LABEL */}
              <TouchableOpacity
                style={[
                  styles.bulkRouteCard,
                  { borderColor: '#7c3aed', backgroundColor: '#f5f3ff' }
                ]}
                onPress={() => {
                  const parts = (selectedUnit?.parts || []).filter(p => selectedItemIds.has(`${p.id}_${unitSideTab}`));
                  handleBulkQcInspect(parts, 'approved', 'PAINT');
                }}>
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 4 }}>
                  <Text style={{ color: '#6b21a8', fontSize: 20, fontWeight: '900', letterSpacing: 1 }}>
                    PAINT
                  </Text>
                  <View style={{ backgroundColor: '#7c3aed', paddingHorizontal: 10, paddingVertical: 4, borderRadius: 6 }}>
                    <Text style={{ color: '#ffffff', fontSize: 11, fontWeight: '800' }}>PAINT SHOP</Text>
                  </View>
                </View>
                <Text style={{ color: '#581c87', fontSize: 12, marginTop: 2, fontWeight: '600' }}>
                  Routes all selected parts into the Paint department queue for coating.
                </Text>
              </TouchableOpacity>

              {/* ASSEMBLY BUTTON WITH PROMINENT TEXT LABEL */}
              <TouchableOpacity
                style={[
                  styles.bulkRouteCard,
                  { borderColor: '#0d9488', backgroundColor: '#f0fdfa', marginTop: 8 }
                ]}
                onPress={() => {
                  const parts = (selectedUnit?.parts || []).filter(p => selectedItemIds.has(`${p.id}_${unitSideTab}`));
                  handleBulkQcInspect(parts, 'approved', 'ASSEMBLY');
                }}>
                <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginBottom: 4 }}>
                  <Text style={{ color: '#115e59', fontSize: 20, fontWeight: '900', letterSpacing: 1 }}>
                    ASSEMBLY
                  </Text>
                  <View style={{ backgroundColor: '#0d9488', paddingHorizontal: 10, paddingVertical: 4, borderRadius: 6 }}>
                    <Text style={{ color: '#ffffff', fontSize: 11, fontWeight: '800' }}>DIRECT ASSEMBLY</Text>
                  </View>
                </View>
                <Text style={{ color: '#134e4a', fontSize: 12, marginTop: 2, fontWeight: '600' }}>
                  Bypasses paint station and moves parts directly to Assembly line.
                </Text>
              </TouchableOpacity>
            </View>

            <TouchableOpacity
              style={[styles.button, { marginTop: 14, backgroundColor: '#94a3b8' }]}
              onPress={() => setShowBulkQcDestinationModal(false)}>
              <Text style={styles.buttonText}>Cancel</Text>
            </TouchableOpacity>
          </View>
        </View>
      </Modal>

      {/* REWORK COMPLETION MODAL (Strict Quantity Processing & Transition to QC) */}
      <Modal visible={showReworkModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={[styles.modalBox, { maxHeight: '90%' }]}>
            <ScrollView showsVerticalScrollIndicator={false}>
              <Text style={styles.modalTitle}>Complete Rework Operation</Text>
              <Text style={styles.itemPartNo}>{selectedReworkItem?.bom_item?.standard_part_no || `Item #${selectedReworkItem?.id}`}</Text>
              
              {(() => {
                const avail = selectedReworkItem?.quantity || 1;
                const rQty = parseInt(reworkQty, 10) || 0;

                return (
                  <View>
                    <View style={[styles.qtySummaryBox, { backgroundColor: '#fffbeb', borderColor: '#fde68a' }]}>
                      <Text style={[styles.qtySummaryText, { color: '#92400e' }]}>
                        Active Rework Quantity: <Text style={{ fontWeight: '800', color: '#b45309' }}>{avail} pcs</Text> ({selectedReworkItem?.side || unitSideTab})
                      </Text>
                    </View>

                    <View style={{ marginTop: 10 }}>
                      <CompactQuantitySelector
                        label={`Completed Quantity to Return to QC (1 to ${avail})`}
                        value={reworkQty}
                        onChange={setReworkQty}
                        max={avail}
                        min={1}
                        color="#f59e0b"
                      />
                    </View>

                    <Text style={[styles.label, { marginTop: 10 }]}>Corrective Action / Completion Notes</Text>
                    <TextInput
                      style={styles.input}
                      value={reworkNotes}
                      onChangeText={setReworkNotes}
                      placeholder="e.g. Surface polished, threads re-tapped, dimensions verified"
                    />

                    <View style={{ flexDirection: 'row', gap: 8, marginTop: 14 }}>
                      <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowReworkModal(false)}>
                        <Text style={styles.buttonText}>Cancel</Text>
                      </TouchableOpacity>
                      <TouchableOpacity
                        style={[
                          styles.button,
                          { flex: 1, backgroundColor: '#f59e0b', opacity: (rQty <= 0 || rQty > avail) ? 0.6 : 1.0 }
                        ]}
                        disabled={rQty <= 0 || rQty > avail}
                        onPress={submitReworkCompletion}>
                        <Text style={styles.buttonText}>Return to QC ({rQty})</Text>
                      </TouchableOpacity>
                    </View>
                  </View>
                );
              })()}
            </ScrollView>
          </View>
        </View>
      </Modal>

      {/* PAINT COMPLETION MODAL (With Quantity Input) */}
      <Modal visible={showPaintModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Complete Painting Operation</Text>
            <Text style={styles.itemPartNo}>{selectedPaintItem?.bom_item?.standard_part_no || `Item #${selectedPaintItem?.id}`}</Text>
            
            {(() => {
              const avail = selectedPaintItem?.available_paint_quantity || selectedPaintItem?.approved_quantity || selectedPaintItem?.quantity || 1;
              const pQty = parseInt(paintQty, 10) || 0;

              return (
                <View>
                  <View style={styles.qtySummaryBox}>
                    <Text style={styles.qtySummaryText}>
                      Available to Paint: <Text style={{ fontWeight: '800', color: '#7c3aed' }}>{avail} pcs</Text> ({selectedPaintItem?.side || 'COMMON'})
                    </Text>
                  </View>

                  <View style={{ marginTop: 10 }}>
                    <CompactQuantitySelector
                      label={`Painted Quantity to Push to Assembly (1 to ${avail})`}
                      value={paintQty}
                      onChange={setPaintQty}
                      max={avail}
                      min={1}
                      color="#7c3aed"
                    />
                  </View>

                  <Text style={[styles.label, { marginTop: 10 }]}>Paint Type / Color Code</Text>
                  <TextInput
                    style={styles.input}
                    value={paintType}
                    onChangeText={setPaintType}
                    placeholder="e.g. RAL 7035 Powder Coat"
                  />

                  <Text style={styles.label}>Process Notes / Remarks</Text>
                  <TextInput
                    style={styles.input}
                    value={paintRemarks}
                    onChangeText={setPaintRemarks}
                    placeholder="Optional notes"
                  />

                  <View style={{ flexDirection: 'row', gap: 8, marginTop: 14 }}>
                    <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowPaintModal(false)}>
                      <Text style={styles.buttonText}>Cancel</Text>
                    </TouchableOpacity>
                    <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#7c3aed' }]} onPress={submitPaintCompletion}>
                      <Text style={styles.buttonText}>Push to Assembly</Text>
                    </TouchableOpacity>
                  </View>
                </View>
              );
            })()}
          </View>
        </View>
      </Modal>



      {/* ASSEMBLY COMPLETION MODAL (With Quantity Input) */}
      <Modal visible={showAssemblyModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Complete Assembly Operation</Text>
            <Text style={styles.itemPartNo}>{selectedAssemblyItem?.standard_part_no || `Part #${selectedAssemblyItem?.id}`}</Text>
            
            {(() => {
              const sideStat = selectedAssemblyItem?.side_stats?.[unitSideTab] || {};
              const avail = sideStat.assembly_ready || 1;
              const aQty = parseInt(assemblyQty, 10) || 0;

              return (
                <View>
                  <View style={styles.qtySummaryBox}>
                    <Text style={styles.qtySummaryText}>
                      Available for Assembly: <Text style={{ fontWeight: '800', color: '#0d9488' }}>{avail} pcs</Text> ({unitSideTab})
                    </Text>
                  </View>

                  <View style={{ marginTop: 10 }}>
                    <CompactQuantitySelector
                      label={`Assembled Quantity (1 to ${avail})`}
                      value={assemblyQty}
                      onChange={setAssemblyQty}
                      max={avail}
                      min={1}
                      color="#0d9488"
                    />
                  </View>

                  <Text style={[styles.label, { marginTop: 10 }]}>Assembly Process Remarks</Text>
                  <TextInput
                    style={styles.input}
                    value={assemblyRemarks}
                    onChangeText={setAssemblyRemarks}
                    placeholder="Optional assembly notes"
                  />

                  <View style={{ flexDirection: 'row', gap: 8, marginTop: 14 }}>
                    <TouchableOpacity
                      style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]}
                      disabled={isSubmittingAssembly}
                      onPress={() => setShowAssemblyModal(false)}>
                      <Text style={styles.buttonText}>Cancel</Text>
                    </TouchableOpacity>
                    <TouchableOpacity
                      style={[styles.button, { flex: 1, backgroundColor: isSubmittingAssembly ? '#0f766e' : '#0d9488' }]}
                      disabled={isSubmittingAssembly}
                      onPress={submitAssemblyCompletion}>
                      {isSubmittingAssembly ? (
                        <ActivityIndicator color="#ffffff" size="small" />
                      ) : (
                        <Text style={styles.buttonText}>Mark Assembled</Text>
                      )}
                    </TouchableOpacity>
                  </View>
                </View>
              );
            })()}
          </View>
        </View>
      </Modal>

      {/* BULK PAINT COMPLETION MODAL (Issue 5) */}
      <Modal visible={showBulkPaintModal} animationType="fade" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Bulk Complete Painting ({selectedItemIds.size} Parts)</Text>
            <Text style={styles.itemSubText}>Side: {unitSideTab}</Text>

            <Text style={[styles.label, { marginTop: 10 }]}>Paint Type / Color Code</Text>
            <TextInput
              style={styles.input}
              value={bulkPaintType}
              onChangeText={setBulkPaintType}
              placeholder="e.g. RAL 7035 Powder Coat"
            />

            <View style={{ flexDirection: 'row', gap: 8, marginTop: 12 }}>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowBulkPaintModal(false)}>
                <Text style={styles.buttonText}>Cancel</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.button, { flex: 1, backgroundColor: '#7c3aed' }]}
                onPress={() => {
                  const parts = (selectedUnit?.parts || []).filter(p => selectedItemIds.has(`${p.id}_${unitSideTab}`));
                  handleBulkPaintComplete(parts);
                }}>
                <Text style={styles.buttonText}>Complete Paint ({selectedItemIds.size})</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>

      {/* BULK REWORK COMPLETION MODAL (Issue 5) */}
      <Modal visible={showBulkReworkModal} animationType="fade" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <Text style={styles.modalTitle}>Bulk Complete Rework ({selectedItemIds.size} Parts)</Text>
            <Text style={styles.itemSubText}>Side: {unitSideTab}</Text>

            <Text style={[styles.label, { marginTop: 12 }]}>Work Performed / Completion Remarks</Text>
            <TextInput
              style={[styles.input, { minHeight: 70, textAlignVertical: 'top' }]}
              value={bulkReworkNotes}
              onChangeText={setBulkReworkNotes}
              multiline
              numberOfLines={3}
              placeholder="Describe corrective actions taken..."
            />

            <View style={{ flexDirection: 'row', gap: 8, marginTop: 14 }}>
              <TouchableOpacity style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]} onPress={() => setShowBulkReworkModal(false)}>
                <Text style={styles.buttonText}>Cancel</Text>
              </TouchableOpacity>
              <TouchableOpacity
                style={[styles.button, { flex: 1, backgroundColor: '#10b981' }]}
                onPress={() => {
                  const parts = (selectedUnit?.parts || []).filter(p => selectedItemIds.has(`${p.id}_${unitSideTab}`));
                  handleBulkReworkAction(parts, 'complete');
                }}>
                <Text style={styles.buttonText}>Complete & Return QC</Text>
              </TouchableOpacity>
            </View>
          </View>
        </View>
      </Modal>

      {/* UNIVERSAL STRICT LINEAGE REVERT MODAL */}
      <Modal visible={showRevertModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 6 }}>
              <Text style={styles.modalTitle}>↩ Revert Part</Text>
              <TouchableOpacity onPress={() => setShowRevertModal(false)} style={{ padding: 4 }}>
                <Text style={{ fontSize: 16, color: '#64748b', fontWeight: '700' }}>✕</Text>
              </TouchableOpacity>
            </View>

            <Text style={styles.itemPartNo}>
              {revertTargetItem?.standard_part_no || `Part #${revertTargetItem?.id}`} ({revertSide})
            </Text>

            {(() => {
              const rQty = parseInt(revertQty, 10) || 0;
              const maxAvail = selectedRevertOption?.available_quantity || 1;

              return (
                <View style={{ marginTop: 8 }}>
                  {/* Lineage Segment Selection (if multiple sources exist, e.g. Assembly with QC + Paint) */}
                  {revertOptionsList.length > 1 && (
                    <View style={{ marginBottom: 10 }}>
                      <Text style={[styles.label, { marginBottom: 4, fontWeight: '700' }]}>Select Lineage Source to Revert:</Text>
                      {revertOptionsList.map((opt, idx) => {
                        const isChosen = selectedRevertOption?.source_id === opt.source_id && selectedRevertOption?.source_type === opt.source_type;
                        return (
                          <TouchableOpacity
                            key={`revert-opt-${idx}`}
                            style={[
                              styles.revertOptionCard,
                              isChosen && styles.revertOptionCardSelected
                            ]}
                            onPress={() => {
                              setSelectedRevertOption(opt);
                              setRevertQty(String(opt.available_quantity || 1));
                            }}>
                            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
                              <Text style={[styles.revertOptionText, isChosen && { fontWeight: '800', color: '#dc2626' }]}>
                                {opt.description}
                              </Text>
                              <Text style={[styles.revertOptionBadge, isChosen && { backgroundColor: '#fee2e2', color: '#b91c1c' }]}>
                                ↩ {opt.target_label}
                              </Text>
                            </View>
                          </TouchableOpacity>
                        );
                      })}
                    </View>
                  )}

                  {/* Read-Only Target Department Display */}
                  <View style={styles.revertDestinationBox}>
                    <Text style={styles.revertDestinationLabel}>Revert Destination (Canonical Lineage):</Text>
                    <Text style={styles.revertDestinationTarget}>
                      ↩ {selectedRevertOption?.target_label || 'Previous Department'}
                    </Text>
                  </View>

                  {/* Quantity Stepper with Compact Selector */}
                  <View style={{ marginTop: 10 }}>
                    <CompactQuantitySelector
                      label={`Quantity to Revert (1 to ${maxAvail})`}
                      value={revertQty}
                      onChange={setRevertQty}
                      max={maxAvail}
                      min={1}
                      color="#dc2626"
                    />
                  </View>

                  {/* Reason Input */}
                  <Text style={[styles.label, { marginTop: 8 }]}>Reason for Revert (Audit Trail)</Text>
                  <TextInput
                    style={[styles.input, { minHeight: 44 }]}
                    value={revertReason}
                    onChangeText={setRevertReason}
                    placeholder="e.g. Allocation correction, quality re-check..."
                  />

                  {/* Action Buttons */}
                  <View style={{ flexDirection: 'row', gap: 8, marginTop: 14 }}>
                    <TouchableOpacity
                      style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]}
                      disabled={isSubmittingRevert}
                      onPress={() => setShowRevertModal(false)}>
                      <Text style={styles.buttonText}>Cancel</Text>
                    </TouchableOpacity>
                    <TouchableOpacity
                      style={[
                        styles.button,
                        { flex: 1.3, backgroundColor: isSubmittingRevert ? '#991b1b' : '#dc2626' },
                        (rQty <= 0 || rQty > maxAvail) && { opacity: 0.5 }
                      ]}
                      disabled={isSubmittingRevert || rQty <= 0 || rQty > maxAvail}
                      onPress={submitRevert}>
                      {isSubmittingRevert ? (
                        <ActivityIndicator color="#ffffff" size="small" />
                      ) : (
                        <Text style={styles.buttonText}>Confirm Revert ({rQty} pcs)</Text>
                      )}
                    </TouchableOpacity>
                  </View>
                </View>
              );
            })()}
          </View>
        </View>
      </Modal>

      {/* BULK GLOBAL REVERT MODAL */}
      <Modal visible={showBulkGlobalRevertModal} animationType="slide" transparent>
        <View style={styles.modalOverlay}>
          <View style={styles.modalBox}>
            <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
              <Text style={styles.modalTitle}>↩ Bulk Revert Parts</Text>
              <TouchableOpacity onPress={() => setShowBulkGlobalRevertModal(false)} style={{ padding: 4 }}>
                <Text style={{ fontSize: 16, color: '#64748b', fontWeight: '700' }}>✕</Text>
              </TouchableOpacity>
            </View>

            {(() => {
              const selectedList = globalRevertItems.filter(i => selectedGlobalRevertIds.has(i.id));
              const totalPcs = selectedList.reduce((sum, i) => sum + (i.available_quantity || 0), 0);

              return (
                <View>
                  <Text style={{ fontSize: 13, color: '#334155', marginBottom: 8, lineHeight: 18 }}>
                    You are about to revert <Text style={{ fontWeight: '800', color: '#dc2626' }}>{selectedList.length} parts ({totalPcs} total pcs)</Text> in {activeTab.toUpperCase()} back to their previous department.
                  </Text>

                  <Text style={[styles.label, { marginTop: 4 }]}>Reason for Bulk Revert (Audit Trail)</Text>
                  <TextInput
                    style={[styles.input, { minHeight: 44 }]}
                    value={bulkGlobalRevertReason}
                    onChangeText={setBulkGlobalRevertReason}
                    placeholder="e.g. Batch quality hold, intake adjustment..."
                  />

                  <View style={{ flexDirection: 'row', gap: 8, marginTop: 14 }}>
                    <TouchableOpacity
                      style={[styles.button, { flex: 1, backgroundColor: '#94a3b8' }]}
                      disabled={isSubmittingGlobalRevert}
                      onPress={() => setShowBulkGlobalRevertModal(false)}>
                      <Text style={styles.buttonText}>Cancel</Text>
                    </TouchableOpacity>
                    <TouchableOpacity
                      style={[styles.button, { flex: 1.4, backgroundColor: isSubmittingGlobalRevert ? '#991b1b' : '#dc2626' }]}
                      disabled={isSubmittingGlobalRevert}
                      onPress={handleBulkGlobalRevertSubmit}>
                      {isSubmittingGlobalRevert ? (
                        <ActivityIndicator color="#ffffff" size="small" />
                      ) : (
                        <Text style={styles.buttonText}>Confirm ({totalPcs} pcs)</Text>
                      )}
                    </TouchableOpacity>
                  </View>
                </View>
              );
            })()}
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
  loginLogo: {
    width: 220,
    height: 48,
    alignSelf: 'center',
    marginBottom: 12,
  },
  headerLogo: {
    width: 92,
    height: 24,
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
  otaUpdateBar: {
    marginTop: 14,
    paddingVertical: 7,
    paddingHorizontal: 12,
    backgroundColor: '#f1f5f9',
    borderRadius: 18,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  otaDot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    marginRight: 6,
  },
  otaText: {
    fontSize: 11,
    fontWeight: '600',
    color: '#475569',
  },
  header: {
    paddingHorizontal: 12,
    paddingVertical: 7,
    backgroundColor: '#ffffff',
    borderBottomWidth: 1,
    borderColor: '#e2e8f0',
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  headerTitle: {
    fontSize: 15,
    fontWeight: 'bold',
    color: '#0f172a',
  },
  userSubtitle: {
    fontSize: 11,
    color: '#64748b',
    marginTop: 1,
  },
  roleBadge: {
    color: '#2563eb',
    fontWeight: 'bold',
  },
  headerUpdateBtn: {
    backgroundColor: '#eff6ff',
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 4,
    borderWidth: 1,
    borderColor: '#93c5fd',
  },
  headerUpdateBtnText: {
    color: '#2563eb',
    fontWeight: 'bold',
    fontSize: 11,
  },
  logoutBtn: {
    backgroundColor: '#fef2f2',
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 4,
    borderWidth: 1,
    borderColor: '#fca5a5',
  },
  logoutBtnText: {
    color: '#ef4444',
    fontWeight: 'bold',
    fontSize: 11,
  },
  tabsContainer: {
    backgroundColor: '#ffffff',
    borderBottomWidth: 1,
    borderColor: '#e2e8f0',
    maxHeight: 38,
    paddingHorizontal: 6,
  },
  tab: {
    paddingHorizontal: 10,
    paddingVertical: 6,
    marginRight: 2,
  },
  activeTab: {
    borderBottomWidth: 2,
    borderBottomColor: '#2563eb',
  },
  tabText: {
    fontSize: 11.5,
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
    padding: 2,
    marginHorizontal: 10,
    marginTop: 4,
    marginBottom: 2,
    borderRadius: 6,
  },
  subTab: {
    flex: 1,
    paddingVertical: 5,
    alignItems: 'center',
    borderRadius: 4,
  },
  activeSubTab: {
    backgroundColor: '#ffffff',
    shadowColor: '#000',
    shadowOpacity: 0.05,
    shadowRadius: 2,
    elevation: 1,
  },
  activeSubTabRevert: {
    backgroundColor: '#ffffff',
    borderColor: '#ea580c',
    borderWidth: 1,
    shadowColor: '#ea580c',
    shadowOpacity: 0.1,
    shadowRadius: 2,
    elevation: 1,
  },
  subTabText: {
    fontSize: 11,
    fontWeight: '600',
    color: '#64748b',
  },
  activeSubTabText: {
    color: '#2563eb',
    fontWeight: 'bold',
  },
  activeSubTabTextRevert: {
    color: '#c2410c',
    fontWeight: 'bold',
  },
  searchBarContainer: {
    flexDirection: 'row',
    paddingHorizontal: 10,
    paddingTop: 5,
    paddingBottom: 2,
    gap: 6,
  },
  searchInput: {
    flex: 1,
    backgroundColor: '#ffffff',
    borderWidth: 1,
    borderColor: '#cbd5e1',
    borderRadius: 6,
    paddingHorizontal: 10,
    paddingVertical: 4,
    fontSize: 12.5,
    height: 32,
  },
  filterBtn: {
    backgroundColor: '#e2e8f0',
    paddingHorizontal: 8,
    height: 32,
    justifyContent: 'center',
    borderRadius: 6,
  },
  clearSearchBtn: {
    backgroundColor: '#fee2e2',
    paddingHorizontal: 8,
    height: 32,
    justifyContent: 'center',
    borderRadius: 6,
  },
  clearSearchBtnText: {
    fontSize: 12,
    fontWeight: 'bold',
    color: '#ef4444',
  },
  filterBtnText: {
    fontSize: 11.5,
    fontWeight: 'bold',
    color: '#334155',
  },
  chipsContainer: {
    flexDirection: 'row',
    paddingHorizontal: 10,
    paddingTop: 3,
    paddingBottom: 1,
    gap: 4,
  },
  chip: {
    backgroundColor: '#eff6ff',
    borderColor: '#93c5fd',
    borderWidth: 1,
    borderRadius: 10,
    paddingHorizontal: 8,
    paddingVertical: 2,
  },
  chipText: {
    color: '#2563eb',
    fontSize: 10,
    fontWeight: '600',
  },
  content: {
    paddingHorizontal: 8,
    paddingVertical: 6,
  },
  cardContainer: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'space-between',
  },
  card: {
    width: '48%',
    padding: 12,
    borderRadius: 8,
    marginBottom: 8,
  },
  cardLabel: {
    color: 'rgba(255,255,255,0.85)',
    fontSize: 10,
    fontWeight: '700',
    textTransform: 'uppercase',
  },
  cardValue: {
    color: '#ffffff',
    fontSize: 22,
    fontWeight: 'bold',
    marginTop: 2,
  },
  listContainer: {
    paddingBottom: 20,
  },
  sectionHeader: {
    fontSize: 11,
    fontWeight: 'bold',
    color: '#64748b',
    marginBottom: 6,
    letterSpacing: 0.5,
  },
  hierarchyNavRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: '#ffffff',
    marginHorizontal: 10,
    marginTop: 4,
    marginBottom: 4,
    paddingHorizontal: 8,
    paddingVertical: 5,
    borderRadius: 6,
    borderWidth: 1,
    borderColor: '#cbd5e1',
    shadowColor: '#000',
    shadowOpacity: 0.05,
    shadowRadius: 2,
    elevation: 2,
  },
  hierarchyNavTitle: {
    fontSize: 11,
    fontWeight: '700',
    color: '#1e293b',
    flex: 1,
  },
  backLevelBtn: {
    backgroundColor: '#2563eb',
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 4,
    marginLeft: 6,
  },
  backLevelBtnText: {
    color: '#ffffff',
    fontSize: 10.5,
    fontWeight: '700',
  },
  jigCard: {
    padding: 8,
    borderRadius: 6,
    marginBottom: 5,
    borderWidth: 1.5,
  },
  jigCardIncomplete: {
    backgroundColor: '#ffffff',
    borderColor: '#e2e8f0',
  },
  jigCardComplete: {
    backgroundColor: '#f0fdf4',
    borderColor: '#22c55e',
  },
  jigName: {
    fontSize: 12.5,
    fontWeight: '700',
    color: '#1e293b',
    flex: 1,
  },
  jigBadge: {
    fontSize: 9,
    fontWeight: '800',
    paddingHorizontal: 5,
    paddingVertical: 1.5,
    borderRadius: 3,
    marginLeft: 6,
  },
  jigBadgeComplete: {
    backgroundColor: '#22c55e',
    color: '#ffffff',
  },
  jigBadgeIncomplete: {
    backgroundColor: '#2563eb',
    color: '#ffffff',
  },
  unitCard: {
    padding: 8,
    borderRadius: 6,
    marginBottom: 5,
    borderWidth: 1.5,
  },
  unitCardIncomplete: {
    backgroundColor: '#ffffff',
    borderColor: '#cbd5e1',
  },
  unitCardComplete: {
    backgroundColor: '#f0fdf4',
    borderColor: '#22c55e',
  },
  mobileSidePanel: {
    flex: 1,
    padding: 6,
    borderRadius: 5,
    borderWidth: 1,
  },
  unitTitle: {
    fontSize: 12.5,
    fontWeight: '700',
    color: '#1e293b',
  },
  unitBadge: {
    fontSize: 9,
    fontWeight: '800',
    paddingHorizontal: 5,
    paddingVertical: 1.5,
    borderRadius: 3,
  },
  unitBadgePending: {
    backgroundColor: '#f59e0b',
    color: '#ffffff',
  },
  progressBarBg: {
    height: 4,
    backgroundColor: '#e2e8f0',
    borderRadius: 2,
    marginTop: 4,
    marginBottom: 3,
    overflow: 'hidden',
  },
  progressBarFill: {
    height: '100%',
    borderRadius: 2,
  },
  tapExploreText: {
    fontSize: 10,
    fontWeight: '700',
    color: '#2563eb',
    marginTop: 2,
  },
  emptyState: {
    backgroundColor: '#ffffff',
    padding: 16,
    borderRadius: 6,
    alignItems: 'center',
  },
  emptyStateText: {
    color: '#94a3b8',
    fontSize: 12,
  },
  itemCard: {
    backgroundColor: '#ffffff',
    padding: 6,
    borderRadius: 5,
    marginBottom: 4,
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  itemHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 2,
  },
  itemPartNo: {
    fontSize: 12,
    fontWeight: 'bold',
    color: '#0f172a',
    flex: 1,
    marginRight: 6,
  },
  itemStatus: {
    fontSize: 9,
    fontWeight: 'bold',
    color: '#2563eb',
    backgroundColor: '#eff6ff',
    paddingHorizontal: 4,
    paddingVertical: 1,
    borderRadius: 3,
  },
  itemSubText: {
    fontSize: 10.5,
    color: '#64748b',
    marginTop: 1,
  },
  actionBtn: {
    paddingVertical: 4.5,
    paddingHorizontal: 6,
    borderRadius: 4,
    alignItems: 'center',
  },
  actionBtnText: {
    color: '#ffffff',
    fontWeight: '800',
    fontSize: 10,
    letterSpacing: 0.2,
  },
  revertBtn: {
    backgroundColor: '#fef2f2',
    borderColor: '#fca5a5',
    borderWidth: 1,
    padding: 5,
    borderRadius: 4,
    marginTop: 4,
    alignItems: 'center',
  },
  revertBtnText: {
    color: '#ef4444',
    fontWeight: 'bold',
    fontSize: 10.5,
  },
  statsRow: {
    marginTop: 3,
    gap: 4,
  },
  sideCardBox: {
    backgroundColor: '#f8fafc',
    padding: 5,
    borderRadius: 4,
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  statBadge: {
    color: '#334155',
    fontSize: 10.5,
    fontWeight: '600',
  },
  smallReceiveBtn: {
    backgroundColor: '#2563eb',
    paddingVertical: 4.5,
    paddingHorizontal: 6,
    borderRadius: 4,
    marginTop: 3,
    alignItems: 'center',
  },
  smallReceiveBtnText: {
    color: '#ffffff',
    fontWeight: 'bold',
    fontSize: 10,
  },
  swipeLegendText: {
    textAlign: 'center',
    fontSize: 10.5,
    color: '#94a3b8',
    fontWeight: '600',
  },
  modalOverlay: {
    flex: 1,
    backgroundColor: 'rgba(0,0,0,0.5)',
    justifyContent: 'center',
    padding: 16,
  },
  modalBox: {
    backgroundColor: '#ffffff',
    borderRadius: 10,
    padding: 16,
  },
  modalTitle: {
    fontSize: 16,
    fontWeight: 'bold',
    color: '#0f172a',
    marginBottom: 10,
  },
  chipBtn: {
    paddingHorizontal: 10,
    paddingVertical: 5,
    borderRadius: 5,
    backgroundColor: '#f1f5f9',
  },
  chipBtnActive: {
    backgroundColor: '#2563eb',
  },
  chipBtnText: {
    fontSize: 11,
    fontWeight: 'bold',
    color: '#475569',
  },
  chipBtnTextActive: {
    color: '#ffffff',
  },
  sidePillLh: {
    backgroundColor: '#e0f2fe',
    borderWidth: 1,
    borderColor: '#38bdf8',
    paddingHorizontal: 5,
    paddingVertical: 1.5,
    borderRadius: 4,
  },
  sidePillTextLh: {
    color: '#0369a1',
    fontWeight: '800',
    fontSize: 9.5,
  },
  sidePillRh: {
    backgroundColor: '#dbeafe',
    borderWidth: 1,
    borderColor: '#60a5fa',
    paddingHorizontal: 5,
    paddingVertical: 1.5,
    borderRadius: 4,
  },
  sidePillTextRh: {
    color: '#1d4ed8',
    fontWeight: '800',
    fontSize: 9.5,
  },
  qcModeRow: {
    flexDirection: 'row',
    gap: 6,
    marginBottom: 8,
  },
  qcModeBtn: {
    flex: 1,
    paddingVertical: 7,
    paddingHorizontal: 8,
    backgroundColor: '#f8fafc',
    borderRadius: 6,
    alignItems: 'center',
    borderWidth: 1.5,
    borderColor: '#e2e8f0',
  },
  qcModeBtnActiveArrival: {
    backgroundColor: '#ecfdf5',
    borderColor: '#10b981',
  },
  qcModeBtnActiveInspection: {
    backgroundColor: '#eff6ff',
    borderColor: '#2563eb',
  },
  qcModeBtnText: {
    fontSize: 11.5,
    fontWeight: '700',
    color: '#64748b',
  },
  qcModeBtnTextActiveArrival: {
    color: '#047857',
    fontWeight: '800',
  },
  qcModeBtnTextActiveInspection: {
    color: '#1d4ed8',
    fontWeight: '800',
  },
  sideSwitchRow: {
    flexDirection: 'row',
    gap: 6,
    marginBottom: 8,
  },
  sideSwitchBtn: {
    flex: 1,
    paddingVertical: 7,
    paddingHorizontal: 8,
    backgroundColor: '#f1f5f9',
    borderRadius: 6,
    alignItems: 'center',
    borderWidth: 1.5,
    borderColor: '#e2e8f0',
  },
  sideSwitchBtnActiveLh: {
    backgroundColor: '#e0f2fe',
    borderColor: '#0284c7',
  },
  sideSwitchBtnActiveRh: {
    backgroundColor: '#dbeafe',
    borderColor: '#2563eb',
  },
  sideSwitchText: {
    fontSize: 11.5,
    fontWeight: '700',
    color: '#64748b',
  },
  sideSwitchTextActiveLh: {
    color: '#0369a1',
    fontWeight: '800',
  },
  sideSwitchTextActiveRh: {
    color: '#1d4ed8',
    fontWeight: '800',
  },
  // Toast Notification Styles (Issue 4)
  toastBanner: {
    paddingVertical: 9,
    paddingHorizontal: 16,
    borderRadius: 8,
    marginHorizontal: 12,
    marginTop: 6,
    marginBottom: 4,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#000',
    shadowOpacity: 0.1,
    shadowRadius: 4,
    elevation: 3,
  },
  toastSuccess: {
    backgroundColor: '#059669',
  },
  toastError: {
    backgroundColor: '#dc2626',
  },
  toastText: {
    color: '#ffffff',
    fontWeight: 'bold',
    fontSize: 12.5,
    textAlign: 'center',
  },
  // Multi-Selection Control Bar Styles (Issue 5)
  selectionControlBar: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 8,
    backgroundColor: '#f8fafc',
    padding: 6,
    borderRadius: 6,
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  selectionToggleBtn: {
    paddingVertical: 5,
    paddingHorizontal: 8,
    backgroundColor: '#e2e8f0',
    borderRadius: 4,
  },
  selectionToggleText: {
    fontSize: 11.5,
    fontWeight: '700',
    color: '#334155',
  },
  selectAllBtn: {
    paddingVertical: 5,
    paddingHorizontal: 8,
    backgroundColor: '#dbeafe',
    borderRadius: 4,
  },
  selectAllBtnText: {
    fontSize: 11.5,
    fontWeight: 'bold',
    color: '#1d4ed8',
  },
  clearSelectBtn: {
    paddingVertical: 5,
    paddingHorizontal: 8,
    backgroundColor: '#fee2e2',
    borderRadius: 4,
  },
  clearSelectBtnText: {
    fontSize: 11.5,
    fontWeight: 'bold',
    color: '#ef4444',
  },
  checkboxCircle: {
    width: 20,
    height: 20,
    borderRadius: 10,
    borderWidth: 2,
    borderColor: '#94a3b8',
    backgroundColor: '#ffffff',
    alignItems: 'center',
    justifyContent: 'center',
  },
  checkboxCircleSelected: {
    borderColor: '#2563eb',
    backgroundColor: '#2563eb',
  },
  checkmarkText: {
    color: '#ffffff',
    fontSize: 12,
    fontWeight: 'bold',
    marginTop: -2,
  },
  // Fixed Sticky Bottom Action Bar
  stickyBottomActionBar: {
    position: 'absolute',
    bottom: 0,
    left: 0,
    right: 0,
    backgroundColor: '#0f172a',
    borderTopLeftRadius: 16,
    borderTopRightRadius: 16,
    paddingHorizontal: 16,
    paddingTop: 12,
    paddingBottom: Platform.OS === 'ios' ? 32 : 16,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: -4 },
    shadowOpacity: 0.3,
    shadowRadius: 10,
    elevation: 24,
    zIndex: 9999,
    borderTopWidth: 1,
    borderLeftWidth: 1,
    borderRightWidth: 1,
    borderColor: '#334155',
  },
  stickyBarHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 8,
  },
  stickyBarCountBadge: {
    color: '#ffffff',
    fontSize: 13.5,
    fontWeight: '700',
    letterSpacing: 0.2,
  },
  stickyBarClearBtn: {
    backgroundColor: 'rgba(255, 255, 255, 0.15)',
    paddingHorizontal: 10,
    paddingVertical: 4,
    borderRadius: 12,
  },
  stickyBarClearText: {
    color: '#cbd5e1',
    fontSize: 11.5,
    fontWeight: '600',
  },
  // Floating Bulk Action Bar (Legacy Support)
  floatingBulkBar: {
    backgroundColor: '#0f172a',
    borderRadius: 12,
    padding: 12,
    marginTop: 12,
    marginBottom: 20,
    shadowColor: '#000',
    shadowOpacity: 0.25,
    shadowRadius: 10,
    elevation: 8,
    borderWidth: 1,
    borderColor: '#334155',
  },
  floatingBulkText: {
    color: '#f8fafc',
    fontWeight: 'bold',
    fontSize: 13,
    textAlign: 'center',
  },
  bulkBtn: {
    paddingVertical: 10,
    paddingHorizontal: 12,
    borderRadius: 7,
    alignItems: 'center',
    justifyContent: 'center',
  },
  bulkBtnText: {
    color: '#ffffff',
    fontWeight: 'bold',
    fontSize: 12.5,
  },
  // Route Selection Cards (Issue 3)
  routeCard: {
    flex: 1,
    padding: 10,
    borderRadius: 8,
    borderWidth: 2,
    backgroundColor: '#f8fafc',
    alignItems: 'center',
  },
  bulkRouteCard: {
    width: '100%',
    padding: 16,
    borderRadius: 12,
    borderWidth: 2.5,
    marginVertical: 4,
  },
  routeCardTitle: {
    fontWeight: 'bold',
    fontSize: 12,
    marginBottom: 2,
    textAlign: 'center',
  },
  routeCardDesc: {
    fontSize: 10,
    color: '#64748b',
    textAlign: 'center',
  },
  // Universal Quantity Stepper & Split Validation Styles
  qtyStepperRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    marginTop: 4,
  },
  qtyBtn: {
    width: 42,
    height: 42,
    borderRadius: 8,
    borderWidth: 1.5,
    borderColor: '#2563eb',
    backgroundColor: '#ffffff',
    alignItems: 'center',
    justifyContent: 'center',
  },
  qtyBtnText: {
    fontSize: 20,
    fontWeight: '800',
    color: '#2563eb',
  },
  qtyInput: {
    flex: 1,
    height: 42,
    borderRadius: 8,
    borderWidth: 1.5,
    borderColor: '#2563eb',
    backgroundColor: '#ffffff',
    textAlign: 'center',
    fontSize: 16,
    fontWeight: '800',
    color: '#0f172a',
  },
  qtySummaryBox: {
    backgroundColor: '#f1f5f9',
    borderRadius: 8,
    padding: 8,
    marginTop: 4,
    borderWidth: 1,
    borderColor: '#e2e8f0',
  },
  qtySummaryText: {
    fontSize: 12,
    color: '#475569',
  },
  qtyRemainingText: {
    fontSize: 11,
    color: '#64748b',
    marginTop: 4,
  },
  validationSuccessBox: {
    backgroundColor: '#ecfdf5',
    borderColor: '#10b981',
    borderWidth: 1,
    borderRadius: 6,
    paddingVertical: 6,
    paddingHorizontal: 8,
  },
  validationSuccessText: {
    color: '#047857',
    fontSize: 11.5,
    fontWeight: '700',
  },
  validationErrorBox: {
    backgroundColor: '#fef2f2',
    borderColor: '#ef4444',
    borderWidth: 1,
    borderRadius: 6,
    paddingVertical: 6,
    paddingHorizontal: 8,
  },
  validationErrorText: {
    color: '#b91c1c',
    fontSize: 11.5,
    fontWeight: '700',
  },
  compactRevertBtn: {
    backgroundColor: '#fff1f2',
    borderWidth: 1,
    borderColor: '#fca5a5',
    borderRadius: 6,
    paddingVertical: 5,
    paddingHorizontal: 8,
    alignItems: 'center',
  },
  compactRevertBtnText: {
    color: '#b91c1c',
    fontWeight: '700',
    fontSize: 11.5,
  },
  revertOptionCard: {
    backgroundColor: '#f8fafc',
    borderWidth: 1,
    borderColor: '#e2e8f0',
    borderRadius: 8,
    padding: 8,
    marginBottom: 6,
  },
  revertOptionCardSelected: {
    borderColor: '#dc2626',
    backgroundColor: '#fef2f2',
  },
  revertOptionText: {
    fontSize: 12,
    color: '#334155',
  },
  revertOptionBadge: {
    fontSize: 11,
    fontWeight: '700',
    color: '#64748b',
    backgroundColor: '#f1f5f9',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 4,
  },
  revertDestinationBox: {
    backgroundColor: '#fef2f2',
    borderWidth: 1,
    borderColor: '#fecaca',
    borderRadius: 8,
    padding: 10,
    marginTop: 4,
  },
  revertDestinationLabel: {
    fontSize: 11,
    color: '#991b1b',
    fontWeight: '600',
  },
  revertDestinationTarget: {
    fontSize: 14,
    fontWeight: '800',
    color: '#dc2626',
    marginTop: 2,
  },
  // Dual Metric Bulk Selection Text
  bulkSelectionDualText: {
    fontSize: 11.5,
    fontWeight: '800',
    color: '#1d4ed8',
    backgroundColor: '#eff6ff',
    paddingHorizontal: 8,
    paddingVertical: 3,
    borderRadius: 6,
    borderWidth: 1,
    borderColor: '#bfdbfe',
  },
  // Department Subtab Switcher Styles
  deptSubtabRow: {
    flexDirection: 'row',
    backgroundColor: '#f1f5f9',
    borderRadius: 9,
    padding: 3,
    marginBottom: 10,
    gap: 4,
  },
  deptSubtabBtn: {
    flex: 1,
    paddingVertical: 7,
    paddingHorizontal: 6,
    borderRadius: 7,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'transparent',
  },
  deptSubtabBtnActive: {
    backgroundColor: '#2563eb',
    shadowColor: '#2563eb',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.15,
    shadowRadius: 3,
    elevation: 2,
  },
  deptSubtabBtnActiveRevert: {
    backgroundColor: '#ea580c',
    shadowColor: '#ea580c',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.2,
    shadowRadius: 3,
    elevation: 2,
  },
  deptSubtabBtnActiveRework: {
    backgroundColor: '#d97706',
    shadowColor: '#d97706',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.2,
    shadowRadius: 3,
    elevation: 2,
  },
  deptSubtabBtnActivePaint: {
    backgroundColor: '#7c3aed',
    shadowColor: '#7c3aed',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.2,
    shadowRadius: 3,
    elevation: 2,
  },
  deptSubtabBtnActiveAssembly: {
    backgroundColor: '#0d9488',
    shadowColor: '#0d9488',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.2,
    shadowRadius: 3,
    elevation: 2,
  },
  deptSubtabBtnActiveCompleted: {
    backgroundColor: '#16a34a',
    shadowColor: '#16a34a',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.2,
    shadowRadius: 3,
    elevation: 2,
  },
  deptSubtabBtnText: {
    fontSize: 11,
    fontWeight: '700',
    color: '#64748b',
    textAlign: 'center',
  },
  deptSubtabBtnTextActive: {
    color: '#ffffff',
    fontWeight: '800',
  },
  deptSubtabBtnTextActiveRevert: {
    color: '#ffffff',
    fontWeight: '800',
  },
  // Compact High-Density Revert Cards
  compactRevertCard: {
    backgroundColor: '#fffdfa',
    borderWidth: 1,
    borderColor: '#fed7aa',
    borderRadius: 8,
    padding: 9,
    marginBottom: 8,
    shadowColor: '#ea580c',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.05,
    shadowRadius: 2,
    elevation: 1,
  },
  compactRevertStatusBadge: {
    fontSize: 10,
    fontWeight: '800',
    color: '#c2410c',
    backgroundColor: '#ffedd5',
    paddingHorizontal: 6,
    paddingVertical: 2,
    borderRadius: 4,
  },
  compactRevertSingleRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginTop: 6,
    paddingTop: 5,
    borderTopWidth: 1,
    borderTopColor: '#fef3c7',
  },
  compactRevertSegmentRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    backgroundColor: '#ffffff',
    borderWidth: 1,
    borderColor: '#ffedd5',
    borderRadius: 6,
    padding: 6,
  },
  compactRevertInfoText: {
    fontSize: 11.5,
    color: '#334155',
    flex: 1,
    marginRight: 6,
  },
  compactRevertLineageSubtext: {
    fontSize: 9.5,
    color: '#64748b',
    marginTop: 1,
  },
  compactRevertActionBtn: {
    backgroundColor: '#ea580c',
    paddingHorizontal: 12,
    paddingVertical: 6,
    borderRadius: 5,
    alignItems: 'center',
    justifyContent: 'center',
    shadowColor: '#ea580c',
    shadowOffset: { width: 0, height: 1 },
    shadowOpacity: 0.15,
    shadowRadius: 2,
    elevation: 1,
  },
  compactRevertActionBtnText: {
    color: '#ffffff',
    fontWeight: '800',
    fontSize: 11.5,
  },
  clearProjectFilterBtn: {
    backgroundColor: '#e2e8f0',
    paddingHorizontal: 8,
    paddingVertical: 4,
    borderRadius: 6,
  },
  clearProjectFilterBtnText: {
    fontSize: 11,
    fontWeight: '700',
    color: '#475569',
  },
  ecnBadge: {
    backgroundColor: '#fef3c7',
    borderWidth: 1,
    borderColor: '#fcd34d',
    paddingHorizontal: 5,
    paddingVertical: 1.5,
    borderRadius: 4,
  },
  ecnBadgeText: {
    fontSize: 10,
    fontWeight: '800',
    color: '#92400e',
  },
  ecnCounterBadge: {
    backgroundColor: '#fef3c7',
    paddingHorizontal: 5,
    paddingVertical: 1.5,
    borderRadius: 4,
    borderWidth: 1,
    borderColor: '#fcd34d',
  },
  ecnCounterBadgeText: {
    fontSize: 10,
    fontWeight: '800',
    color: '#b45309',
  },
});
