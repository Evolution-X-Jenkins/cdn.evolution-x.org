<?php
ob_start();
?>

<div class="max-w-6xl mx-auto">
    <!-- Header -->
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
            <h2 class="text-4xl font-normal text-white opacity-90 font-prodsans">Pre-Release Files</h2>
            <p class="text-gray-300 mt-2">
                <?php echo $preReleasePath ? htmlspecialchars($preReleasePath) : 'Root Directory'; ?>
            </p>
        </div>
    </div>
    
    <!-- Breadcrumb Navigation -->
    <?php if ($preReleasePath): ?>
    <div class="mb-6">
        <nav class="flex items-center space-x-2 text-sm">
            <a href="/pre-release" class="text-[#0060ff] hover:text-blue-300 transition-colors">Pre-Release</a>
            <?php
            $pathParts = explode('/', trim($preReleasePath, '/'));
            $currentPath = '';
            foreach ($pathParts as $part):
                $currentPath .= '/' . $part;
            ?>
                <svg class="w-4 h-4 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                </svg>
                <a href="/pre-release<?php echo $currentPath; ?>" class="text-[#0060ff] hover:text-blue-300 transition-colors">
                    <?php echo htmlspecialchars($part); ?>
                </a>
            <?php endforeach; ?>
        </nav>
    </div>
    <?php endif; ?>
    
    <!-- Action Bar -->
    <div class="mb-6 bg-[#0f172a] border border-gray-700 rounded-lg p-4">
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div class="text-white">
                <span class="text-sm text-gray-300">Files: <?php echo count($files); ?></span>
            </div>
            <div class="flex gap-2">
                <a href="/" class="inline-flex items-center px-4 py-2 border border-gray-600 text-sm font-medium rounded-lg text-white bg-gray-700 hover:bg-gray-600 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7M3 7l9-5 9 5"></path>
                    </svg>
                    Main Directory
                </a>
                <button onclick="copySelectedFiles()" class="inline-flex items-center px-4 py-2 border border-[#0060ff] text-sm font-medium rounded-lg text-white bg-[#0060ff] hover:bg-[#004bb5] transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                    </svg>
                    Copy to Main
                </button>
            </div>
        </div>
    </div>
    
    <!-- File Listing -->
    <div class="bg-[#0f172a] border-2 border-[#0060ff] shadow-[0px_0px_38.5px_14px_#0060ff20] rounded-lg overflow-hidden">
        <?php if (empty($files)): ?>
            <div class="p-8 text-center text-gray-300">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <p>No files found in this directory</p>
            </div>
        <?php else: ?>
            <div class="divide-y divide-gray-700">
                <?php foreach ($files as $file): ?>
                    <div class="flex items-center justify-between p-4 hover:bg-gray-800 transition-colors group">
                        <div class="flex items-center space-x-4">
                            <input type="checkbox" class="file-checkbox w-4 h-4 text-[#0060ff] border-gray-600 rounded focus:ring-[#0060ff] bg-gray-700" 
                                   value="<?php echo htmlspecialchars($file['path']); ?>">
                                   
                            <div class="flex items-center space-x-3">
                                <?php if ($file['type'] === 'directory'): ?>
                                    <svg class="w-6 h-6 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
                                        <path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"></path>
                                    </svg>
                                    <a href="/pre-release/<?php echo htmlspecialchars($file['path']); ?>" 
                                       class="text-white hover:text-[#0060ff] transition-colors">
                                        <span class="font-medium"><?php echo htmlspecialchars($file['name']); ?></span>
                                    </a>
                                <?php else: ?>
                                    <svg class="w-6 h-6 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    <span class="text-white font-medium"><?php echo htmlspecialchars($file['name']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="flex items-center space-x-6 text-sm text-gray-400">
                            <?php if ($file['size']): ?>
                                <span><?php echo format_file_size($file['size']); ?></span>
                            <?php endif; ?>
                            <span><?php echo date('M j, Y H:i', $file['modified']); ?></span>
                            <span class="font-mono"><?php echo $file['permissions']; ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
function copySelectedFiles() {
    const checkboxes = document.querySelectorAll('.file-checkbox:checked');
    if (checkboxes.length === 0) {
        alert('Please select files to copy');
        return;
    }
    
    const files = Array.from(checkboxes).map(cb => cb.value);
    
    if (confirm(`Copy ${files.length} file(s) to main directory?`)) {
        // Show loading state
        const button = event.target;
        const originalText = button.innerHTML;
        button.innerHTML = '<svg class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>Copying...';
        button.disabled = true;
        
        // Copy files via API
        Promise.all(files.map(file => 
            fetch('/api/file-operations', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    action: 'copy_from_prerelease',
                    source_path: file
                })
            })
        )).then(responses => {
            return Promise.all(responses.map(r => r.json()));
        }).then(results => {
            const successful = results.filter(r => r.success).length;
            const failed = results.length - successful;
            
            if (failed === 0) {
                alert(`Successfully copied ${successful} file(s)`);
                // Uncheck all checkboxes
                checkboxes.forEach(cb => cb.checked = false);
            } else {
                alert(`Copied ${successful} file(s), ${failed} failed`);
            }
        }).catch(error => {
            alert('Error copying files: ' + error.message);
        }).finally(() => {
            button.innerHTML = originalText;
            button.disabled = false;
        });
    }
}

// Select all functionality
document.addEventListener('DOMContentLoaded', function() {
    // Add select all checkbox
    const actionBar = document.querySelector('.bg-\\[\\#0f172a\\].border.border-gray-700');
    if (actionBar) {
        const selectAllHtml = `
            <label class="flex items-center text-sm text-gray-300">
                <input type="checkbox" id="select-all" class="w-4 h-4 text-[#0060ff] border-gray-600 rounded focus:ring-[#0060ff] bg-gray-700 mr-2">
                Select All
            </label>
        `;
        const filesSpan = actionBar.querySelector('span');
        filesSpan.insertAdjacentHTML('afterend', selectAllHtml);
        
        // Handle select all
        document.getElementById('select-all').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.file-checkbox');
            checkboxes.forEach(cb => cb.checked = this.checked);
        });
    }
});
</script>

<?php
$content = ob_get_clean();
$page_title = 'Pre-Release Files';
include 'layout.php';
?>