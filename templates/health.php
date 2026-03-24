<?php
ob_start();
?>

<div class="max-w-4xl mx-auto">
    <!-- Header with Evolution X logo -->
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
            <h2 class="text-4xl font-normal text-white opacity-90 font-prodsans">System Health</h2>
        </div>
    </div>
    
    <!-- Overall Status Banner -->
    <div class="mb-8">
        <?php
        $banner_class = 'bg-green-900 border-green-500';
        $icon = '✅';
        $status_text = 'All Systems Operational';
        
        if ($overall_status === 'error') {
            $banner_class = 'bg-red-900 border-red-500';
            $icon = '❌';
            $status_text = 'System Issues Detected';
        } elseif ($overall_status === 'warning') {
            $banner_class = 'bg-yellow-900 border-yellow-500';
            $icon = '⚠️';
            $status_text = 'Minor Issues Detected';
        }
        ?>
        <div class="<?php echo $banner_class; ?> border-2 rounded-lg p-6 text-center">
            <div class="text-4xl mb-2"><?php echo $icon; ?></div>
            <h3 class="text-2xl font-bold text-white"><?php echo $status_text; ?></h3>
            <p class="text-gray-300 mt-2">Last updated: <?php echo date('Y-m-d H:i:s T'); ?></p>
        </div>
    </div>
    
    <!-- Service Status Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 gap-6 mb-8">
        <?php foreach ($health_checks as $key => $check): ?>
            <?php
            $card_class = 'border-green-500';
            $icon = '✅';
            $text_color = 'text-green-400';
            
            if ($check['status'] === 'error') {
                $card_class = 'border-red-500';
                $icon = '❌';
                $text_color = 'text-red-400';
            } elseif ($check['status'] === 'warning') {
                $card_class = 'border-yellow-500';
                $icon = '⚠️';
                $text_color = 'text-yellow-400';
            } elseif ($check['status'] === 'building') {
                $card_class = 'border-blue-500';
                $icon = '🔄';
                $text_color = 'text-blue-400';
            } elseif ($check['status'] === 'idle') {
                $card_class = 'border-gray-500';
                $icon = '⏸️';
                $text_color = 'text-gray-400';
            }
            ?>
            
            <div class="bg-[#0f172a] border-2 <?php echo $card_class; ?> rounded-lg p-6 hover:shadow-lg transition-shadow">
                <div class="flex items-center justify-between mb-4">
                    <h4 class="text-lg font-semibold text-white"><?php echo htmlspecialchars($check['name']); ?></h4>
                    <span class="text-2xl"><?php echo $icon; ?></span>
                </div>
                
                <p class="<?php echo $text_color; ?> font-medium mb-3">
                    <?php echo htmlspecialchars($check['message']); ?>
                </p>
                
                <?php if (!empty($check['details'])): ?>
                    <div class="space-y-1">
                        <?php foreach ($check['details'] as $label => $value): ?>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-400"><?php echo htmlspecialchars($label); ?>:</span>
                                <span class="text-white" title="<?php echo htmlspecialchars($value); ?>">
                                    <?php 
                                    $display_value = $value;
                                    if (strlen($value) > 30 && (strpos($label, 'URL') !== false || filter_var($value, FILTER_VALIDATE_URL))) {
                                        $display_value = substr($value, 0, 27) . '...';
                                    }
                                    echo htmlspecialchars($display_value); 
                                    ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- Quick Actions -->
    <div class="bg-[#0f172a] border-2 border-[#0060ff] shadow-[0px_0px_38.5px_14px_#0060ff20] rounded-lg p-6">
        <h3 class="text-xl font-semibold text-white mb-4">Quick Actions</h3>
        <div class="flex flex-wrap gap-4">
            <a href="/" class="inline-flex items-center justify-center px-4 py-2 border border-gray-600 text-sm font-medium rounded-lg text-white bg-gray-700 hover:bg-gray-600 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V7M3 7l9-5 9 5" />
                </svg>
                File Browser
            </a>
            
            <button onclick="location.reload()" class="inline-flex items-center justify-center px-4 py-2 border border-[#0060ff] text-sm font-medium rounded-lg text-white bg-[#0060ff] hover:bg-[#004bb5] transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                Refresh Status
            </button>
            
            <?php if (isset($health_checks['logs']) && $health_checks['logs']['status'] !== 'error'): ?>
            <a href="/logs" class="inline-flex items-center justify-center px-4 py-2 border border-gray-600 text-sm font-medium rounded-lg text-white bg-gray-700 hover:bg-gray-600 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
                View Logs
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Auto-refresh every 30 seconds
setTimeout(() => {
    location.reload();
}, 30000);
</script>

<?php
$content = ob_get_clean();
$page_title = 'System Health';
include 'layout.php';
?>