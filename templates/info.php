<?php
ob_start();
?>

<div class="max-w-5xl mx-auto space-y-6 p-6">
    <!-- File Header -->
    <div class="bg-[#0f172a] border-2 border-[#0060ff] shadow-[0px_0px_38.5px_14px_#0060ff20] rounded-lg p-6">
        <div class="flex items-center justify-between mb-4">
            <h1 class="text-sm md:text-2xl font-bold text-white flex items-center">
                <svg class="w-8 h-8 mr-3 text-[#0060ff]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
                <?php echo htmlspecialchars($file_name); ?>
            </h1>
            <span class="bg-[#0060ff] text-white px-3 py-1 rounded-full text-sm font-medium text-center">
                <?php echo htmlspecialchars($file_type); ?>
            </span>
        </div>
        
        <!-- File Details -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div>
                <span class="text-gray-400">Path:</span>
                <span class="text-white ml-2 font-mono"><?php echo htmlspecialchars($parent_dir); ?></span>
            </div>
            <div>
                <span class="text-gray-400">Size:</span>
                <span class="text-white ml-2"><?php echo htmlspecialchars($file_size_formatted); ?></span>
            </div>
            <div>
                <span class="text-gray-400">Modified:</span>
                <span class="text-white ml-2"><?php echo date('M j, Y H:i', $file_modified); ?></span>
            </div>
        </div>
    </div>
    
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Download Statistics -->
        <div class="bg-[#0f172a] border border-gray-700 rounded-lg p-6">
            <h3 class="text-xl font-semibold text-white mb-4 flex items-center">
                <svg class="w-5 h-5 mr-2 text-[#0060ff]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v4a2 2 0 01-2 2h-2a2 2 0 00-2-2z"></path>
                </svg>
                Download Statistics
            </h3>
            
            <div class="grid grid-cols-1 gap-4 mb-6">
                <div class="text-center">
                    <div class="text-4xl font-bold text-[#0060ff]"><?php echo number_format($total_downloads); ?></div>
                    <div class="text-sm text-gray-400">Total Downloads</div>
                </div>
            </div>
            
            <!-- Daily Download Chart -->
            <div class="mb-4">
                <h4 class="text-lg font-semibold text-white mb-3">Daily Breakdown</h4>
                <div class="bg-gray-800 rounded-lg p-4">
                    <div class="flex items-end justify-between space-x-2 px-2" style="height: 180px; overflow: visible;">
                        <?php 
                        $max_downloads = max(array_values($chart_data));
                        $container_height = 120;
                        foreach ($chart_data as $date => $count): 
                            // Calculate height relative to the highest bar
                            if ($count > 0 && $max_downloads > 0) {
                                $scale_factor = $count / $max_downloads;
                                $scaled_height = round($scale_factor * $container_height); // Scale from 0 to full height based on ratio
                                $scaled_height = max($scaled_height, 8); // Ensure minimum 8px visibility for any data
                            } else {
                                $scaled_height = 4; // Empty days get 4px
                            }
                            $day_name = date('D', strtotime($date));
                            $is_today = $date === date('Y-m-d');
                        ?>
                        <div class="flex flex-col items-center flex-1">
                            <div class="w-full <?php echo $count > 0 ? 'bg-[#0060ff] hover:bg-[#004bb5]' : 'bg-gray-600 hover:bg-gray-500'; ?> rounded-t-sm transition-all duration-300 shadow-sm" 
                                 style="height: <?php echo $scaled_height; ?>px !important; max-height: none; min-height: 4px;"
                                 title="<?php echo $day_name . ' (' . date('M j', strtotime($date)) . '): ' . $count . ' downloads'; ?>">
                            </div>
                            <div class="text-xs <?php echo $is_today ? 'text-[#0060ff] font-semibold' : 'text-gray-400'; ?> mt-1 font-medium"><?php echo $day_name; ?></div>
                            <div class="text-xs <?php echo $count > 0 ? 'text-white font-medium' : 'text-gray-500'; ?>"><?php echo $count; ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-xs text-gray-500 mt-2 text-center">
                        Hover over bars for details
                    </div>
                </div>
            </div>
        </div>

        <!-- File Hashes -->
        <div class="bg-[#0f172a] border border-gray-700 rounded-lg p-6">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-semibold text-white flex items-center">
                    <svg class="w-5 h-5 mr-2 text-[#0060ff]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    File Hashes
                </h3>
                <?php if (empty($hashes) && !$hashes_calculating): ?>
                <button onclick="calculateHashes()" class="px-3 py-1 bg-[#0060ff] text-white text-sm rounded hover:bg-[#004bb5] transition-colors">
                    Calculate Hashes
                </button>
                <?php elseif ($hashes_calculating): ?>
                <span class="px-3 py-1 bg-yellow-600 text-white text-sm rounded">
                    Calculating...
                </span>
                <?php endif; ?>
            </div>
            
            <div class="space-y-4">
                <?php if (!empty($hashes)): ?>
                    <?php foreach ($hashes as $algorithm => $hash): ?>
                    <div>
                        <div class="flex justify-between items-center mb-1">
                            <span class="text-sm font-medium text-gray-300"><?php echo strtoupper($algorithm); ?></span>
                            <button onclick="copyToClipboard('<?php echo $hash; ?>')" class="text-xs text-[#0060ff] hover:text-blue-300">
                                Copy
                            </button>
                        </div>
                        <div class="bg-gray-800 p-3 rounded font-mono text-sm text-gray-200 break-all">
                            <?php echo $hash; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php elseif ($hashes_calculating): ?>
                <div class="text-yellow-400 text-center py-4">
                    <svg class="w-8 h-8 mx-auto mb-2 text-yellow-500 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    Hashes are being calculated in the background. Refresh the page in a few moments.
                </div>
                <?php else: ?>
                <div id="ota-hash-loading" class="text-blue-400 text-center py-4">
                    <svg class="w-8 h-8 mx-auto mb-2 text-blue-500 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                    </svg>
                    Loading hashes from OTA server...
                </div>
                <div id="ota-hash-not-found" style="display:none;" class="text-gray-400 text-center py-4">
                    <svg class="w-8 h-8 mx-auto mb-2 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    No hashes found in OTA database. Click "Calculate Hashes" to generate them.
                </div>
                <button onclick="calculateHashes()" id="calculate-hashes-btn" style="display:none;" class="px-3 py-1 bg-[#0060ff] text-white text-sm rounded hover:bg-[#004bb5] transition-colors">
                    Calculate Hashes
                </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Actions -->
    <div class="flex flex-col sm:flex-row gap-4 justify-center">
        <a href="<?php echo htmlspecialchars('/' . ltrim($relative_file_path, '/') . '/download'); ?>" 
           class="inline-flex items-center justify-center px-8 py-4 border border-transparent text-lg font-medium rounded-full text-white bg-[#0060ff] hover:bg-[#004bb5] transition-all duration-300 shadow-lg">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Download File
        </a>
        
        <a href="<?php echo htmlspecialchars($parent_dir); ?>" 
           class="inline-flex items-center justify-center px-8 py-4 border border-gray-600 text-lg font-medium rounded-full text-white bg-gray-700 hover:bg-gray-600 transition-all duration-300">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
            </svg>
            Back to Files
        </a>
    </div>
</div>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        // You could add a toast notification here
        alert('Copied to clipboard!');
    });
}

/**
 * Load hashes from OTA device.json on GitHub
 * Parses file path to extract device name, then fetches from Evolution-X/OTA repository
 * and matches filename to get md5 and sha256 hashes
 */
async function loadOTAHashes() {
    try {
        const filePath = '<?php echo addslashes($relative_file_path); ?>';
        const fileName = '<?php echo addslashes($file_name); ?>';
        
        // Extract device name from path (e.g., /raphael/16/filename.zip -> raphael)
        const pathParts = filePath.split('/').filter(p => p);
        if (pathParts.length < 2) {
            console.log('Invalid file path structure for OTA lookup');
            showCalculateHashesButton();
            return;
        }
        
        const deviceName = pathParts[0];
        console.log('Device name:', deviceName, 'File name:', fileName);
        
        // Fetch device.json from GitHub Evolution-X/OTA repository
        // Try primary device branch first, then fallback to vic and udc branches
        const branches = ['bka', 'vic', 'udc'];
        const otaUrls = branches.map(branch => 
            `https://raw.githubusercontent.com/Evolution-X/OTA/refs/heads/${branch}/builds/${deviceName}.json`
        );
        
        let otaData = null;
        for (const url of otaUrls) {
            try {
                console.log('Trying GitHub OTA URL:', url);
                const response = await fetch(url, { 
                    signal: AbortSignal.timeout(5000),
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                if (response.ok) {
                    otaData = await response.json();
                    console.log('Fetched OTA data from GitHub:', url);
                    break;
                }
            } catch (error) {
                console.log('GitHub OTA URL failed:', url, error.message);
                continue;
            }
        }
        
        if (!otaData || !otaData.response || !Array.isArray(otaData.response)) {
            console.log('Invalid OTA data structure or no data found');
            showCalculateHashesButton();
            return;
        }
        
        console.log('OTA Response entries:', otaData.response.length);
        console.log('Looking for file:', fileName);
        otaData.response.forEach((entry, idx) => {
            console.log(`Entry ${idx}: ${entry.filename}`);
        });
        
        // Find matching file entry
        // Try exact match first, then try without extension variations
        let fileEntry = otaData.response.find(entry => entry.filename === fileName);
        
        if (!fileEntry) {
            // Try without extension
            const fileNameWithoutExt = fileName.replace(/\.(zip|img|bin)$/, '');
            fileEntry = otaData.response.find(entry => 
                entry.filename === fileNameWithoutExt ||
                entry.filename === `${fileNameWithoutExt}.zip`
            );
        }
        
        if (!fileEntry) {
            // Try partial match - look for entries that contain the device name and similar date pattern
            const devicePattern = fileName.split('-')[0]; // e.g., "EvolutionX"
            fileEntry = otaData.response.find(entry => 
                entry.filename && entry.filename.includes(devicePattern) && 
                entry.md5 && entry.sha256
            );
        }
        
        if (!fileEntry || !fileEntry.md5 || !fileEntry.sha256) {
            console.log('File not found in OTA database or hashes missing');
            showCalculateHashesButton();
            return;
        }
        
        // Display the hashes and store them
        displayOTAHashes(fileEntry);
        console.log('Successfully loaded hashes from GitHub OTA');
        
    } catch (error) {
        console.error('Error loading OTA hashes:', error);
        showCalculateHashesButton();
    }
}

/**
 * Display hashes loaded from OTA and store them in database
 */
function displayOTAHashes(fileEntry) {
    const loadingDiv = document.getElementById('ota-hash-loading');
    const notFoundDiv = document.getElementById('ota-hash-not-found');
    const calculateBtn = document.getElementById('calculate-hashes-btn');
    
    if (loadingDiv) loadingDiv.style.display = 'none';
    if (notFoundDiv) notFoundDiv.style.display = 'none';
    if (calculateBtn) calculateBtn.style.display = 'none';
    
    // Create hash display HTML
    const hashesContainer = document.querySelector('.space-y-4');
    const hashesHtml = `
        <div>
            <div class="flex justify-between items-center mb-1">
                <span class="text-sm font-medium text-gray-300">MD5</span>
                <button onclick="copyToClipboard('${fileEntry.md5}')" class="text-xs text-[#0060ff] hover:text-blue-300">
                    Copy
                </button>
            </div>
            <div class="bg-gray-800 p-3 rounded font-mono text-sm text-gray-200 break-all">
                ${fileEntry.md5}
            </div>
        </div>
        <div>
            <div class="flex justify-between items-center mb-1">
                <span class="text-sm font-medium text-gray-300">SHA256</span>
                <button onclick="copyToClipboard('${fileEntry.sha256}')" class="text-xs text-[#0060ff] hover:text-blue-300">
                    Copy
                </button>
            </div>
            <div class="bg-gray-800 p-3 rounded font-mono text-sm text-gray-200 break-all">
                ${fileEntry.sha256}
            </div>
        </div>
        <div class="text-xs text-gray-500 text-center mt-2">
            Hashes from Evolution X OTA server
        </div>
    `;
    
    // Clear existing content and insert new hashes
    if (hashesContainer) {
        hashesContainer.innerHTML = hashesHtml;
    }
    
    // Store hashes in database via API call
    storeHashesInDatabase(fileEntry);
}

/**
 * Store hashes retrieved from OTA in the database for caching
 */
function storeHashesInDatabase(fileEntry) {
    try {
        const filePath = '<?php echo addslashes($relative_file_path); ?>';
        
        // Send hashes to backend API to store in database
        fetch('/api/store-hashes', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                file_path: filePath,
                md5: fileEntry.md5,
                sha256: fileEntry.sha256,
                source: 'ota'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                console.log('Hashes stored in database successfully');
            } else {
                console.log('Failed to store hashes in database:', data.message);
            }
        })
        .catch(error => {
            console.log('Error storing hashes in database:', error);
        });
    } catch (error) {
        console.error('Error in storeHashesInDatabase:', error);
    }
}

/**
 * Show calculate hashes button when OTA lookup fails
 */
function showCalculateHashesButton() {
    const loadingDiv = document.getElementById('ota-hash-loading');
    const notFoundDiv = document.getElementById('ota-hash-not-found');
    const calculateBtn = document.getElementById('calculate-hashes-btn');
    
    if (loadingDiv) loadingDiv.style.display = 'none';
    if (notFoundDiv) notFoundDiv.style.display = 'block';
    if (calculateBtn) calculateBtn.style.display = 'inline-block';
}

function calculateHashes() {
    const button = event.target;
    const originalText = button.textContent;
    button.textContent = 'Calculating...';
    button.disabled = true;
    
    fetch('/api/file-hashes', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            file_path: '<?php echo addslashes($relative_file_path); ?>',
            algorithms: ['md5', 'sha1', 'sha256']
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            alert('Error calculating hashes: ' + data.error);
        } else {
            // Refresh the page to show the new hashes
            location.reload();
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    })
    .finally(() => {
        button.textContent = originalText;
        button.disabled = false;
    });
}

// Load OTA hashes when page is fully loaded
document.addEventListener('DOMContentLoaded', function() {
    // Check if hashes are already cached (not in loading state)
    const loadingDiv = document.getElementById('ota-hash-loading');
    if (loadingDiv && loadingDiv.style.display !== 'none') {
        // Hashes are not cached, attempt to load from OTA
        console.log('No cached hashes found, attempting to load from OTA...');
        loadOTAHashes();
    }
});
</script>

<?php
$content = ob_get_clean();
$page_title = 'File Stats - ' . $file_name;
include 'layout.php';
?>