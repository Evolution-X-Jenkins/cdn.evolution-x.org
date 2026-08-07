<?php
ob_start();
?>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const relativePath = <?php echo json_encode($relative_path, JSON_UNESCAPED_SLASHES); ?>;
        const initialItems = <?php echo json_encode($initial_items ?? [], JSON_UNESCAPED_SLASHES); ?>;
        const searchInput = document.getElementById('file-search');
        const listContainer = document.getElementById('file-list');
        const emptyState = document.getElementById('file-search-empty');
        const loadingState = document.getElementById('file-loading');
        const errorState = document.getElementById('file-error');
        if (!searchInput || !listContainer) {
            return;
        }

        const rowClasses = 'flex items-center space-x-2 text-white hover:underline border-2 border-[#0060ff] bg-[#0f172a] shadow-[0px_0px_38.5px_14px_#0060ff20] rounded-lg px-4 py-2 h-[50px] duration-100 ease-in hover:scale-105 hover:shadow-[0px_0px_38.5px_18px_#0060ff50]';
        const fileRowClasses = 'flex items-center justify-between space-x-2 text-white border-2 border-[#0060ff] bg-[#0f172a] shadow-[0px_0px_38.5px_14px_#0060ff20] rounded-lg px-4 py-2 duration-100 ease-in hover:scale-105 hover:shadow-[0px_0px_38.5px_18px_#0060ff50]';
        const fileLinkClasses = 'flex items-center space-x-2 flex-grow';
        const statsButtonClasses = 'inline-flex w-full items-center justify-center rounded-full bg-[#0060ff] text-white transition-all duration-300 hover:bg-[#004bb5] p-[5px]';

        const parentIcon = '<svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7M3 7l9-5 9 5" /></svg>';
        const statsIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="24px" height="24px" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#ffffff" stroke-width="1.5"/><path d="M12 17V11" stroke="#ffffff" stroke-width="1.5" stroke-linecap="round"/><circle cx="1" cy="1" r="1" transform="matrix(1 0 0 -1 11 9)" fill="#ffffff"/></svg>';

        let items = Array.isArray(initialItems) ? initialItems : [];

        function normalizePath(path) {
            if (!path || path === '/') {
                return '/';
            }
            return '/' + path.replace(/^\/+|\/+$/g, '').replace(/\/+/g, '/');
        }

        function getParentPath(path) {
            const normalized = normalizePath(path);
            if (normalized === '/') {
                return '/';
            }

            const parts = normalized.split('/').filter(Boolean);
            parts.pop();
            return parts.length === 0 ? '/' : '/' + parts.join('/');
        }

        function focusSearchInput() {
            searchInput.focus();
            searchInput.select();
        }

        function getItemIcon(item, isDir) {
            if (item && typeof item.icon === 'string' && item.icon.trim() !== '') {
                return item.icon;
            }

            if (isDir) {
                return '<svg class="w-5 h-5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 00-1.883 2.542l.857 6a2.25 2.25 0 002.227 1.932H19.05a2.25 2.25 0 002.227-1.932l.857-6a2.25 2.25 0 00-1.883-2.542m-16.5 0V6A2.25 2.25 0 016 3.75h3.879a1.125 1.125 0 01.966.542l.818 1.364a2.25 2.25 0 001.932 1.094H18A2.25 2.25 0 0120.25 9v.776" /></svg>';
            }

            return '<svg class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5-3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z" /></svg>';
        }

        function createParentRow() {
            const wrapper = document.createElement('div');
            wrapper.setAttribute('data-file-name', '..');

            const link = document.createElement('a');
            link.setAttribute('href', getParentPath(relativePath));
            link.setAttribute('class', rowClasses);
            link.innerHTML = parentIcon + '<span>...</span>';

            wrapper.appendChild(link);
            return wrapper;
        }

        function createDirectoryRow(item) {
            const wrapper = document.createElement('div');
            wrapper.setAttribute('data-file-name', item.name);

            const link = document.createElement('a');
            link.setAttribute('href', item.path);
            link.setAttribute('class', rowClasses);
            link.innerHTML = getItemIcon(item, true);

            const label = document.createElement('span');
            label.textContent = item.name + '/';
            link.appendChild(label);

            wrapper.appendChild(link);
            return wrapper;
        }

        function createFileRow(item) {
            const wrapper = document.createElement('div');
            wrapper.setAttribute('data-file-name', item.name);

            const row = document.createElement('div');
            row.setAttribute('class', fileRowClasses);

            const fileLink = document.createElement('a');
            fileLink.setAttribute('href', item.path);
            fileLink.setAttribute('class', fileLinkClasses);
            fileLink.innerHTML = getItemIcon(item, false);

            const label = document.createElement('span');
            label.textContent = item.name;
            fileLink.appendChild(label);

            const actions = document.createElement('div');
            actions.setAttribute('class', 'flex space-x-3');

            const statsButton = document.createElement('a');
            statsButton.setAttribute('href', item.path + '/stats');
            statsButton.setAttribute('class', statsButtonClasses);
            statsButton.innerHTML = statsIcon;

            actions.appendChild(statsButton);
            row.appendChild(fileLink);
            row.appendChild(actions);
            wrapper.appendChild(row);

            return wrapper;
        }

        function setMessageState(showLoading, showError, showSearchEmpty) {
            if (loadingState) {
                loadingState.classList.toggle('hidden', !showLoading);
            }
            if (errorState) {
                errorState.classList.toggle('hidden', !showError);
            }
            if (emptyState) {
                emptyState.classList.toggle('hidden', !showSearchEmpty);
            }
        }

        function renderList(query) {
            const normalizedQuery = query.trim().toLowerCase();
            const fragment = document.createDocumentFragment();

            if (normalizePath(relativePath) !== '/') {
                fragment.appendChild(createParentRow());
            }

            const filtered = items.filter(function(item) {
                return !normalizedQuery || (item.name || '').toLowerCase().includes(normalizedQuery);
            });

            filtered.forEach(function(item) {
                if (item.is_dir) {
                    fragment.appendChild(createDirectoryRow(item));
                    return;
                }
                fragment.appendChild(createFileRow(item));
            });

            listContainer.querySelectorAll('[data-file-name]').forEach(function(node) {
                node.remove();
            });
            listContainer.prepend(fragment);

            setMessageState(false, false, normalizedQuery !== '' && filtered.length === 0);

            if (normalizedQuery === '' && normalizePath(relativePath) === '/' && filtered.length === 0 && errorState && loadingState) {
                errorState.classList.add('hidden');
                loadingState.classList.remove('hidden');
                loadingState.textContent = 'No files or folders.';
            }
        }

        function updateSearchUrl(query) {
            const url = new URL(window.location.href);
            if (query) {
                url.searchParams.set('q', query);
            } else {
                url.searchParams.delete('q');
            }
            window.history.replaceState({}, '', url);
        }

        function applyLiveFilter() {
            const query = searchInput.value.trim().toLowerCase();
            renderList(query);
            updateSearchUrl(searchInput.value.trim());
        }

        async function loadListing(showLoading) {
            if (showLoading) {
                setMessageState(true, false, false);
            }

            try {
                const response = await fetch('/api/bucket-listing?path=' + encodeURIComponent(relativePath), {
                    method: 'GET',
                    headers: {
                        'Accept': 'application/json'
                    }
                });

                const payload = await response.json();
                if (!response.ok || !payload.success || !payload.data || !Array.isArray(payload.data.items)) {
                    throw new Error(payload.error || 'Invalid listing response');
                }

                items = payload.data.items;
                applyLiveFilter();
            } catch (error) {
                console.error('Failed to load bucket listing', error);
                if (showLoading || items.length === 0) {
                    setMessageState(false, true, false);
                }
            }
        }

        document.addEventListener('keydown', function (event) {
            const target = event.target;
            const isTypingTarget = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable);
            const isModifier = event.ctrlKey || event.metaKey || event.altKey || event.shiftKey;
            const isNavigationKey = ['Arrow', 'Tab', 'Escape', 'Enter', 'Backspace', 'Delete', 'Home', 'End', 'PageUp', 'PageDown'].some(function (key) {
                return event.key.startsWith(key);
            });

            if (isTypingTarget || isModifier || isNavigationKey || event.key.length !== 1) {
                return;
            }

            focusSearchInput();
        });

        searchInput.addEventListener('input', applyLiveFilter);
        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
            }
        });

        if (items.length > 0) {
            applyLiveFilter();
            loadListing(false);
        } else {
            loadListing(true);
        }
    });
</script>

<div class="mb-8">
    <div class="text-2xl mb-2 text-center max-w-lg mx-auto w-[300px]">
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
        <br>
        <h2 class="text-2xl font-normal text-white opacity-90 font-prodsans">Download Server</h2>
    </div>

    <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between mb-2">
        <h2 class="text-lg text-white">
            Folder:
            <span class="text-blue-900 font-medium"><?php echo htmlspecialchars($relative_path); ?></span>
        </h2>

        <form method="get" action="" class="w-full md:w-80">
            <label for="file-search" class="sr-only">Search files</label>
            <input
                id="file-search"
                name="q"
                type="search"
                value="<?php echo htmlspecialchars((string)($_GET['q'] ?? '')); ?>"
                placeholder="Search files..."
                class="w-full rounded-lg border border-[#0060ff] bg-[#0f172a] px-4 py-2 text-white placeholder:text-slate-400 focus:outline-none focus:ring-2 focus:ring-[#0060ff]"
            >
        </form>
    </div>

</div>

    <!-- File listing -->
    <div id="file-list" class="grid gap-4">
        <div id="file-loading" class="text-gray-400 italic py-4">Loading files from storage bucket...</div>
        <div id="file-error" class="hidden text-red-400 italic py-4">Unable to load files from storage bucket right now.</div>
        <div id="file-search-empty" class="hidden text-gray-600 italic py-4">No matching files or folders.</div>
    </div>

<?php
$content = ob_get_clean();
$page_title = 'Evolution X CDN - ' . ($relative_path === '/' ? 'Home' : basename($relative_path));
include 'layout.php';
?>
