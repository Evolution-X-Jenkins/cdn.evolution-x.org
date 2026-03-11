<?php
ob_start();
?>

<div class="max-w-6xl mx-auto space-y-6 p-6">
    <!-- Header with Evolution X logo (matches health page style) -->
    <div class="mb-8">
        <div class="text-center mb-8">
            <div class="mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" width="300" height="85" viewBox="0 0 495 85" fill="none" class="mx-auto">
                    <path d="M444.773 0.216797H424.794L447.436 40.8392L423.462 82.7936L444.773 83.4595L458.757 54.824L474.074 83.4595L494.718 82.7936L471.41 40.8392L494.718 0.216797H474.074L458.757 27.5204L444.773 0.216797Z" fill="white"/>
                    <path d="M261.918 19.5291H246.602L245.27 31.516H237.278L235.281 44.1689H242.606L239.942 55.4899C238.832 59.9295 237.241 70.065 239.276 74.1363C240.608 76.8 243.938 82.7935 248.599 82.7935H259.92C262.584 82.7935 263.916 80.1297 264.582 78.1319C265.115 76.5337 265.914 72.1384 265.914 69.4747C265.914 68.8087 263.916 70.1406 259.254 70.1406C251.929 70.1406 253.261 65.479 253.927 61.4834C253.927 61.4834 255.259 56.1558 255.925 52.8261C256.591 49.4964 257.257 44.1689 257.257 44.1689H269.909L271.907 31.516H260.586L261.918 19.5291Z" fill="white"/>
                    <path d="M193.992 65.4791C192.66 61.4834 196.878 40.1733 198.654 30.8501H184.669C183.781 39.7293 181.339 48.8305 180.007 57.4878C179.188 62.8153 178.675 67.4769 178.675 71.4725C178.675 75.4682 185.739 82.1276 189.996 82.1276H207.977V80.1298H210.641V82.1276H224.625L233.949 30.8501H219.964C219.964 30.8501 212.638 61.4834 210.641 65.4791C208.643 69.4747 196.776 73.8313 193.992 65.4791Z" fill="white"/>
                    <path d="M382.454 47.4987C383.785 51.4944 379.568 72.8045 377.792 82.1277H391.777C392.665 73.2485 395.106 64.1473 396.438 55.49C397.258 50.1625 397.77 45.5009 397.77 41.5052C397.77 37.5096 390.707 30.8502 386.449 30.8502L368.469 30.8502V32.848H365.805V30.8502H351.82L342.497 82.1277L356.482 82.1277C356.482 82.1277 363.807 51.4944 365.805 47.4987C367.803 43.5031 379.67 39.1465 382.454 47.4987Z" fill="white"/>
                    <path d="M56.8081 0.216797H2.86682L16.8516 14.2016H54.1443L56.8081 0.216797Z" fill="white"/>
                    <path d="M52.1465 30.8501H4.19865L0.203003 45.5008L42.8233 82.1276L46.8189 65.4791L24.1769 45.5008H56.1421L52.1465 30.8501Z" fill="white"/>
                    <path d="M68.1291 82.7936L57.474 30.8501H72.1247L78.7841 65.4791L98.7624 30.8501H114.745L82.1138 82.7936H68.1291Z" fill="white"/>
                    <path d="M156.699 82.7935L169.352 8.20801H184.669L172.016 82.7935H156.699Z" fill="white"/>
                    <path d="M266.58 82.1276L275.903 30.8501H289.888L281.23 82.1276H266.58Z" fill="white"/>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M131.393 84.1253C148.042 82.1274 156.033 72.8042 158.697 57.4876C161.361 42.1713 148.898 28.818 131.393 29.518C114.745 30.1839 104.756 42.1709 103.424 57.4876C102.758 72.8042 114.745 85.4572 131.393 84.1253ZM131.477 70.7575C139.843 69.7364 143.859 64.9718 145.198 57.1444C146.536 49.317 140.273 42.4929 131.477 42.8506C123.111 43.1909 118.091 49.3168 117.422 57.1444C117.087 64.9718 123.111 71.4381 131.477 70.7575Z" fill="white"/>
                    <path fill-rule="evenodd" clip-rule="evenodd" d="M315.863 84.1253C332.91 82.1274 341.093 72.8042 343.82 57.4876C346.548 42.1713 333.786 28.818 315.863 29.518C298.816 30.1839 288.588 42.1709 287.225 57.4876C286.543 72.8042 298.816 85.4572 315.863 84.1253ZM315.947 70.7575C324.712 69.7364 328.919 64.9718 330.321 57.1444C331.723 49.317 325.162 42.4929 315.947 42.8506C307.182 43.1909 301.924 49.3168 301.223 57.1444C300.872 64.9718 307.182 71.4381 315.947 70.7575Z" fill="white"/>
                    <path d="M297.076 15.7639C296.056 21.3549 292.996 24.7582 286.622 25.4875C280.247 25.9737 275.658 21.3549 275.913 15.7639C276.423 10.1727 280.247 5.79711 286.622 5.55405C293.324 5.2985 298.095 10.1729 297.076 15.7639Z" fill="white"/>
                </svg>
            </div>
            <h2 class="text-4xl font-normal text-white opacity-90 font-prodsans">Download Statistics</h2>
        </div>
    </div>

    <div id="stats-error" class="hidden bg-red-900/40 border border-red-500 text-red-200 rounded-lg p-4 text-sm"></div>

    <div id="stats-summary-section" class="relative bg-green-900 border-2 border-green-500 rounded-lg p-6 text-center overflow-hidden">
        <div id="stats-loading-summary" class="absolute inset-0 z-10 bg-[#040214]/65 backdrop-blur-sm flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-150 ease-out">
            <div class="w-10 h-10 border-4 border-[#0060ff]/30 border-t-[#0060ff] rounded-full animate-spin"></div>
        </div>
        <div class="text-4xl mb-2">📥</div>
        <h2 class="text-2xl font-bold text-white">Total Downloads</h2>
        <p class="text-gray-300 mt-2">Since <span id="since-date" class="font-medium">N/A</span></p>
        <div id="total-downloads" class="text-5xl font-bold text-white mt-3">0</div>
    </div>

    <div class="border-t border-white/10"></div>

    <div id="stats-breakdown-section" class="relative bg-[#0f172a] border border-gray-700 rounded-lg p-6 overflow-hidden">
        <div id="stats-loading-breakdown" class="absolute inset-0 z-10 bg-[#040214]/65 backdrop-blur-sm flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-150 ease-out">
            <div class="w-10 h-10 border-4 border-[#0060ff]/30 border-t-[#0060ff] rounded-full animate-spin"></div>
        </div>
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-4">
            <h2 class="text-2xl font-semibold text-white">Download Breakdown</h2>
            <div class="w-full md:w-72">
                <label for="breakdown-timeframe" class="block text-sm text-gray-400 mb-2">Timeframe</label>
                <select id="breakdown-timeframe" class="w-full bg-gray-800 border border-gray-600 text-white rounded-lg px-3 py-2 focus:outline-none focus:border-[#0060ff]">
                    <option value="today">Today</option>
                    <option value="7d" selected>Last 7 Days</option>
                    <option value="30d">Last 30 Days</option>
                    <option value="all">All Time</option>
                </select>
            </div>
        </div>

        <div id="breakdown-chart" class="bg-gray-800 rounded-lg p-4 overflow-x-auto">
            <div id="breakdown-bars" class="flex items-end justify-between space-x-2 px-2 min-w-[320px]" style="height: 200px; overflow: visible;"></div>
            <div id="breakdown-caption" class="text-xs text-gray-500 mt-2 text-center">Chart shows daily download counts</div>
        </div>
    </div>

    <div class="border-t border-white/10"></div>

    <div id="stats-top-devices-section" class="relative bg-[#0f172a] border border-gray-700 rounded-lg p-6 overflow-hidden">
        <div id="stats-loading-top-devices" class="absolute inset-0 z-10 bg-[#040214]/65 backdrop-blur-sm flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-150 ease-out">
            <div class="w-10 h-10 border-4 border-[#0060ff]/30 border-t-[#0060ff] rounded-full animate-spin"></div>
        </div>
        <div class="flex flex-col lg:flex-row lg:items-end lg:justify-between gap-4 mb-4">
            <h2 class="text-2xl font-semibold text-white">Top Devices</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 w-full lg:w-auto">
                <div>
                    <label for="devices-timeframe" class="block text-sm text-gray-400 mb-2">Timeframe</label>
                    <select id="devices-timeframe" class="w-full bg-gray-800 border border-gray-600 text-white rounded-lg px-3 py-2 focus:outline-none focus:border-[#0060ff]">
                        <option value="today">Today</option>
                        <option value="7d" selected>Last 7 Days</option>
                        <option value="30d">Last 30 Days</option>
                        <option value="all">All Time</option>
                    </select>
                </div>
                <div>
                    <label for="device-filter" class="block text-sm text-gray-400 mb-2">Device Filter</label>
                    <textarea
                        id="device-filter"
                        rows="1"
                        class="w-full bg-gray-800 border border-gray-600 text-white rounded-lg px-3 py-2 focus:outline-none focus:border-[#0060ff] resize-y"
                        placeholder="e.g. sweet"
                    ></textarea>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto rounded-lg border border-gray-700">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-800/80 text-gray-300 uppercase text-xs tracking-wide">
                    <tr>
                        <th class="text-left px-4 py-3">Device Name</th>
                        <th class="text-right px-4 py-3">Downloads</th>
                    </tr>
                </thead>
                <tbody id="top-devices-table" class="divide-y divide-gray-700"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    const state = {
        breakdownTimeframe: '7d',
        devicesTimeframe: '7d',
        device: '',
        totalDownloads: 0,
        sinceDate: null,
        breakdownSeries: [],
        topDevices: [],
        topDevice: null
    };

    const el = {
        totalDownloads: document.getElementById('total-downloads'),
        sinceDate: document.getElementById('since-date'),
        breakdownTimeframe: document.getElementById('breakdown-timeframe'),
        devicesTimeframe: document.getElementById('devices-timeframe'),
        deviceFilter: document.getElementById('device-filter'),
        breakdownBars: document.getElementById('breakdown-bars'),
        breakdownCaption: document.getElementById('breakdown-caption'),
        topDevicesTable: document.getElementById('top-devices-table'),
        topDeviceName: document.getElementById('top-device-name'),
        topDeviceDownloads: document.getElementById('top-device-downloads'),
        summaryLoadingOverlay: document.getElementById('stats-loading-summary'),
        breakdownLoadingOverlay: document.getElementById('stats-loading-breakdown'),
        topDevicesLoadingOverlay: document.getElementById('stats-loading-top-devices'),
        error: document.getElementById('stats-error')
    };

    const loadingSectionCounts = {
        summary: 0,
        breakdown: 0,
        topDevices: 0
    };
    const CACHE_TTL_MS = 5 * 60 * 1000;
    const dashboardCache = new Map();
    const timeframeOptions = ['today', '7d', '30d', 'all'];

    function formatNumber(value) {
        return Number(value || 0).toLocaleString();
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showError(message) {
        el.error.textContent = message;
        el.error.classList.remove('hidden');
    }

    function clearError() {
        el.error.classList.add('hidden');
        el.error.textContent = '';
    }

    function getLoadingOverlayElement(sectionKey) {
        if (sectionKey === 'summary') return el.summaryLoadingOverlay;
        if (sectionKey === 'breakdown') return el.breakdownLoadingOverlay;
        if (sectionKey === 'topDevices') return el.topDevicesLoadingOverlay;
        return null;
    }

    function beginSectionLoading(sectionKeys) {
        sectionKeys.forEach((sectionKey) => {
            if (!(sectionKey in loadingSectionCounts)) {
                return;
            }

            loadingSectionCounts[sectionKey]++;
            const overlay = getLoadingOverlayElement(sectionKey);
            if (overlay) {
                overlay.classList.remove('opacity-0', 'pointer-events-none');
                overlay.classList.add('opacity-100');
            }
        });
    }

    function endSectionLoading(sectionKeys) {
        sectionKeys.forEach((sectionKey) => {
            if (!(sectionKey in loadingSectionCounts)) {
                return;
            }

            loadingSectionCounts[sectionKey] = Math.max(0, loadingSectionCounts[sectionKey] - 1);
            if (loadingSectionCounts[sectionKey] === 0) {
                const overlay = getLoadingOverlayElement(sectionKey);
                if (overlay) {
                    overlay.classList.remove('opacity-100');
                    overlay.classList.add('opacity-0', 'pointer-events-none');
                }
            }
        });
    }

    async function fetchJson(url) {
        const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
        const payload = await response.json();

        if (!response.ok) {
            throw new Error(payload.error || payload.message || 'Request failed');
        }

        return payload;
    }

    function getDashboardCacheKey(params) {
        return JSON.stringify({
            breakdownTimeframe: params.breakdownTimeframe,
            devicesTimeframe: params.devicesTimeframe,
            device: (params.device || '').trim().toLowerCase()
        });
    }

    function getCachedDashboard(params) {
        const cacheKey = getDashboardCacheKey(params);
        const cached = dashboardCache.get(cacheKey);
        if (!cached) {
            return null;
        }

        if ((Date.now() - cached.cachedAt) > CACHE_TTL_MS) {
            dashboardCache.delete(cacheKey);
            return null;
        }

        return cached.payload;
    }

    function setCachedDashboard(params, payload) {
        const cacheKey = getDashboardCacheKey(params);
        dashboardCache.set(cacheKey, {
            cachedAt: Date.now(),
            payload
        });
    }

    async function fetchDashboardPayload(params, preferCache = true) {
        if (preferCache) {
            const cached = getCachedDashboard(params);
            if (cached) {
                return cached;
            }
        }

        const query = new URLSearchParams();
        query.set('breakdownTimeframe', params.breakdownTimeframe);
        query.set('devicesTimeframe', params.devicesTimeframe);

        const deviceValue = (params.device || '').trim();
        if (deviceValue !== '') {
            query.set('device', deviceValue);
        }

        const payload = await fetchJson('/api/stats-dashboard?' + query.toString());
        setCachedDashboard(params, payload);
        return payload;
    }

    async function prefetchLikelyDashboardCombos() {
        const baseDevice = '';
        const prefetchParams = [];

        timeframeOptions.forEach((tf) => {
            if (tf !== state.breakdownTimeframe) {
                prefetchParams.push({
                    breakdownTimeframe: tf,
                    devicesTimeframe: state.devicesTimeframe,
                    device: baseDevice
                });
            }

            if (tf !== state.devicesTimeframe) {
                prefetchParams.push({
                    breakdownTimeframe: state.breakdownTimeframe,
                    devicesTimeframe: tf,
                    device: baseDevice
                });
            }
        });

        const unique = new Map();
        prefetchParams.forEach((params) => {
            unique.set(getDashboardCacheKey(params), params);
        });

        for (const params of unique.values()) {
            try {
                await fetchDashboardPayload(params, true);
            } catch (error) {
                // Ignore prefetch failures; interactive fetches still handle errors.
            }
        }
    }

    async function loadStatsDashboard() {
        const payload = await fetchDashboardPayload({
            breakdownTimeframe: state.breakdownTimeframe,
            devicesTimeframe: state.devicesTimeframe,
            device: state.device
        }, true);

        state.totalDownloads = Number((payload.summary && payload.summary.total_downloads) || 0);
        state.sinceDate = (payload.summary && payload.summary.since_date) || null;
        state.breakdownSeries = (payload.download_breakdown && Array.isArray(payload.download_breakdown.series))
            ? payload.download_breakdown.series
            : [];
        state.topDevices = (payload.top_devices && Array.isArray(payload.top_devices.rows))
            ? payload.top_devices.rows
            : [];
        state.topDevice = (payload.top_devices && payload.top_devices.top_device) || null;

        renderSummary();
        renderBreakdownChart();
        renderTopDevices();
    }

    function renderSummary() {
        el.totalDownloads.textContent = formatNumber(state.totalDownloads);
        el.sinceDate.textContent = state.sinceDate || 'N/A';
    }

    function renderBreakdownChart() {
        if (!state.breakdownSeries.length) {
            el.breakdownBars.innerHTML = '<div class="text-gray-400 text-sm">No breakdown data available.</div>';
            if (el.breakdownCaption) {
                el.breakdownCaption.textContent = state.breakdownTimeframe === 'all'
                    ? 'Chart shows monthly download counts'
                    : 'Chart shows daily download counts';
            }
            return;
        }

        if (el.breakdownCaption) {
            el.breakdownCaption.textContent = state.breakdownTimeframe === 'all'
                ? 'Chart shows monthly download counts'
                : 'Chart shows daily download counts';
        }

        const max = Math.max(...state.breakdownSeries.map((item) => Number(item.downloads || 0)), 1);

        const useCompactBars = state.breakdownSeries.length > 14;

        el.breakdownBars.innerHTML = state.breakdownSeries.map((item) => {
            const downloads = Number(item.downloads || 0);
            const height = downloads > 0
                ? Math.max(8, Math.round((downloads / max) * 130))
                : 4;

            const itemLabel = item.label || item.day || item.date || '';
            const isToday = !item.label && item.date === new Date().toISOString().slice(0, 10);

            return `
                <div class="flex flex-col items-center ${useCompactBars ? 'w-12 flex-shrink-0' : 'flex-1'}">
                    <div
                        class="w-full ${downloads > 0 ? 'bg-[#0060ff] hover:bg-[#004bb5]' : 'bg-gray-600 hover:bg-gray-500'} rounded-t-sm transition-all duration-300 shadow-sm"
                        style="height: ${height}px; min-height: 4px;"
                        title="${escapeHtml(itemLabel)}: ${downloads} downloads"
                    ></div>
                    <div class="text-xs mt-1 font-medium ${isToday ? 'text-[#0060ff] font-semibold' : 'text-gray-400'}">${escapeHtml(itemLabel)}</div>
                    <div class="text-xs ${downloads > 0 ? 'text-white font-medium' : 'text-gray-500'}">${formatNumber(downloads)}</div>
                </div>
            `;
        }).join('');
    }

    function renderTopDevices() {
        if (el.topDeviceName && el.topDeviceDownloads) {
            if (state.topDevice) {
                el.topDeviceName.textContent = state.topDevice.display_name || state.topDevice.device || '-';
                el.topDeviceDownloads.textContent = formatNumber(state.topDevice.downloads || 0);
            } else {
                el.topDeviceName.textContent = '-';
                el.topDeviceDownloads.textContent = '0';
            }
        }

        if (!state.topDevices.length) {
            el.topDevicesTable.innerHTML = `
                <tr>
                    <td colspan="2" class="px-4 py-4 text-center text-gray-400">No downloads found for this filter.</td>
                </tr>
            `;
            return;
        }

        el.topDevicesTable.innerHTML = state.topDevices.map((row) => `
            <tr class="hover:bg-gray-800/60 transition-colors">
                <td class="px-4 py-3 text-white">${escapeHtml(row.display_name || row.device || 'Unknown')}</td>
                <td class="px-4 py-3 text-right text-[#0060ff] font-semibold">${formatNumber(row.downloads || 0)}</td>
            </tr>
        `).join('');
    }

    async function refreshDashboard(sectionKeys = ['summary', 'breakdown', 'topDevices']) {
        beginSectionLoading(sectionKeys);
        try {
            clearError();
            await loadStatsDashboard();
        } catch (error) {
            showError('Failed to update stats: ' + error.message);
        } finally {
            endSectionLoading(sectionKeys);
        }
    }

    function registerEvents() {
        let deviceFilterDebounceTimer = null;

        el.breakdownTimeframe.addEventListener('change', () => {
            state.breakdownTimeframe = el.breakdownTimeframe.value;
            refreshDashboard(['breakdown']);
        });

        el.devicesTimeframe.addEventListener('change', () => {
            state.devicesTimeframe = el.devicesTimeframe.value;
            refreshDashboard(['topDevices']);
        });

        el.deviceFilter.addEventListener('input', () => {
            if (deviceFilterDebounceTimer) {
                clearTimeout(deviceFilterDebounceTimer);
            }

            deviceFilterDebounceTimer = setTimeout(() => {
                state.device = (el.deviceFilter.value || '').trim();
                refreshDashboard(['topDevices']);
            }, 350);
        });

        el.deviceFilter.addEventListener('blur', () => {
            if (deviceFilterDebounceTimer) {
                clearTimeout(deviceFilterDebounceTimer);
                deviceFilterDebounceTimer = null;
            }

            state.device = (el.deviceFilter.value || '').trim();
            refreshDashboard(['topDevices']);
        });
    }

    async function initialize() {
        registerEvents();
        await refreshDashboard();
        prefetchLikelyDashboardCombos();
    }

    document.addEventListener('DOMContentLoaded', initialize);
})();
</script>

<?php
$content = ob_get_clean();
$page_title = 'Evolution X CDN - Stats';
include 'layout.php';
?>
