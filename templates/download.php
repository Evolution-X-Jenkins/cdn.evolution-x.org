<?php
ob_start();
?>

<div class="max-w-lg mx-auto bg-[#0f172a] border-2 border-[#0060ff] shadow-[0px_0px_38.5px_14px_#0060ff20] rounded-lg shadow-md p-8 mt-8 text-center" id="download-box">
  <h1 class="text-2xl mb-3">Preparing your download…</h1>
  <p class="mb-2 text-white">File: <span class="text-[#0060ff]"><?php echo htmlspecialchars($file_name); ?></span></p>
  <p class="mb-4 text-white">Download will start in <span id="counter" class="font-mono text-xl">5</span> seconds.</p>
  <div id="proxy-notice" class="mb-4 text-yellow-400 text-sm" style="display:none;">
    ⚠️ Using proxy mode due to network restrictions
  </div>
  <div class="flex items-center justify-center mt-4">
    <svg class="animate-spin w-7 h-7 text-[#0060ff]" fill="none" viewBox="0 0 24 24">
      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
    </svg>
  </div>
</div>

<div class="max-w-lg mx-auto bg-[#0f172a] border-2 border-[#0060ff] shadow-[0px_0px_38.5px_14px_#0060ff20] rounded-lg shadow-md p-8 mt-8 text-center" id="redirect-box" style="display:none;">
  <h1 class="text-2xl mb-3">Download started!</h1>
  <p class="mb-4 text-white">You will be returned to the previous page in <span id="redirect-counter" class="font-mono text-xl">5</span> seconds.</p>
  <a href="<?php echo htmlspecialchars($parent_dir); ?>" class="inline-flex h-10 w-full items-center justify-center rounded-full bg-[#0060ff] text-lg text-white transition-all duration-300 hover:bg-[#004bb5] my-4">Return now</a>
</div>

<div class="max-w-lg mx-auto bg-[#0f172a] border-2 border-[#0060ff] shadow-[0px_0px_38.5px_14px_#0060ff20] rounded-lg shadow-md p-8 mt-8 text-center">
  <p class="mb-4 text-white">While you wait, why not take the time to join our socials!</p>
  <div class="my-5 flex h-12 justify-evenly gap-4">
    <a href="https://discord.com/invite/evolution-x-670512508871639041" target="_blank" rel="noreferrer">
      <svg xmlns="http://www.w3.org/2000/svg" width="51" height="51" viewBox="0 0 51 51" fill="none">
        <rect width="51" height="51" rx="25.5" fill="white" />
        <path
          d="M35.8838 15.9228C33.9837 15.0705 31.9265 14.4519 29.7836 14.0945C29.7459 14.095 29.71 14.1098 29.6836 14.1357C29.4265 14.5894 29.1265 15.1805 28.9265 15.6341C26.6535 15.3044 24.3421 15.3044 22.0691 15.6341C21.8691 15.1667 21.5691 14.5894 21.2977 14.1357C21.2834 14.1082 21.2405 14.0945 21.1977 14.0945C19.0548 14.4519 17.0118 15.0705 15.0975 15.9228C15.0832 15.9228 15.0689 15.9365 15.0546 15.9503C11.1688 21.5451 10.0974 26.9886 10.626 32.3772C10.626 32.4047 10.6402 32.4322 10.6688 32.446C13.2403 34.2605 15.7118 35.3602 18.1547 36.0888C18.1976 36.1025 18.2404 36.0888 18.2547 36.0613C18.8262 35.3052 19.3405 34.5079 19.7833 33.6694C19.8119 33.6144 19.7833 33.5594 19.7262 33.5457C18.9119 33.2433 18.1404 32.8859 17.3833 32.4735C17.3261 32.446 17.3261 32.3635 17.369 32.3223C17.5261 32.2123 17.6833 32.0886 17.8404 31.9786C17.869 31.9511 17.9119 31.9511 17.9404 31.9649C22.8549 34.123 28.155 34.123 33.0123 31.9649C33.0409 31.9511 33.0837 31.9511 33.1123 31.9786C33.2694 32.1023 33.4266 32.2123 33.5837 32.336C33.6409 32.3773 33.6409 32.4597 33.5694 32.4872C32.8266 32.9134 32.0408 33.257 31.2265 33.5594C31.1694 33.5732 31.1551 33.6419 31.1694 33.6832C31.6265 34.5217 32.1408 35.319 32.698 36.075C32.7408 36.0888 32.7837 36.1025 32.8266 36.0888C35.2838 35.3602 37.7553 34.2605 40.3268 32.446C40.3553 32.4322 40.3696 32.4047 40.3696 32.3772C40.9982 26.1501 39.3267 20.7478 35.9409 15.9503C35.9266 15.9365 35.9124 15.9228 35.8838 15.9228ZM20.5262 29.0919C19.0548 29.0919 17.8262 27.7859 17.8262 26.1776C17.8262 24.5693 19.0262 23.2634 20.5262 23.2634C22.0406 23.2634 23.2406 24.583 23.2263 26.1776C23.2263 27.7859 22.0263 29.0919 20.5262 29.0919ZM30.4836 29.0919C29.0122 29.0919 27.7836 27.7859 27.7836 26.1776C27.7836 24.5693 28.9836 23.2634 30.4836 23.2634C31.998 23.2634 33.198 24.583 33.1837 26.1776C33.1837 27.7859 31.998 29.0919 30.4836 29.0919Z"
          fill="black" />
      </svg>
    </a>
    <a href="https://github.com/Evolution-X" target="_blank" rel="noreferrer">
      <svg xmlns="http://www.w3.org/2000/svg" width="51" height="51" viewBox="0 0 51 51" fill="none">
        <rect width="51" height="51" rx="25.5" fill="white" />
        <path
          d="M19.2803 14.4123C20.2194 14.7292 21.1126 15.1689 21.9365 15.7198C23.1009 15.4218 24.2984 15.2722 25.5003 15.2748C26.7415 15.2748 27.939 15.4298 29.0615 15.7185C29.8852 15.1681 30.7779 14.7289 31.7165 14.4123C32.5878 14.116 33.829 13.636 34.5665 14.4523C35.0665 15.0073 35.1915 15.9373 35.2803 16.6473C35.3803 17.4398 35.404 18.4723 35.1415 19.4973C36.1453 20.7935 36.7503 22.3398 36.7503 24.0248C36.7503 26.5773 35.3678 28.7935 33.3215 30.3285C32.3363 31.0572 31.2442 31.629 30.084 32.0235C30.3515 32.636 30.5003 33.3135 30.5003 34.0248V37.7748C30.5003 38.1063 30.3686 38.4243 30.1342 38.6587C29.8997 38.8931 29.5818 39.0248 29.2503 39.0248H21.7503C21.4188 39.0248 21.1008 38.8931 20.8664 38.6587C20.632 38.4243 20.5003 38.1063 20.5003 37.7748V36.536C19.3065 36.6823 18.3053 36.5523 17.454 36.191C16.564 35.8135 15.944 35.2285 15.4778 34.6685C15.0353 34.1385 14.5528 32.9435 13.8553 32.711C13.6995 32.6592 13.5555 32.5771 13.4314 32.4696C13.3073 32.3621 13.2056 32.2311 13.1322 32.0843C12.9838 31.7878 12.9593 31.4444 13.064 31.1298C13.1688 30.8152 13.3942 30.5551 13.6908 30.4067C13.9873 30.2583 14.3307 30.2338 14.6453 30.3385C15.4778 30.616 16.0203 31.216 16.3915 31.6985C16.9915 32.4735 17.479 33.486 18.429 33.8898C18.8203 34.056 19.394 34.1648 20.2915 34.0423L20.5003 33.9998C20.5032 33.3196 20.6448 32.6471 20.9165 32.0235C19.7564 31.629 18.6643 31.0572 17.679 30.3285C15.6328 28.7935 14.2503 26.5785 14.2503 24.0248C14.2503 22.3423 14.854 20.7973 15.8553 19.5023C15.5928 18.4773 15.6153 17.4423 15.7153 16.6485L15.7215 16.601C15.8128 15.8735 15.919 15.0173 16.429 14.4523C17.1665 13.636 18.409 14.1173 19.279 14.4135L19.2803 14.4123Z"
          fill="black" />
      </svg>
    </a>
    <a href="https://x.com/EvolutionXROM" target="_blank" rel="noreferrer" class="rounded-full bg-white p-2 w-[51px] h-[51px]">
      <svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="36px" height="36px" viewBox="0 0 50 50">
        <path d="M 5.9199219 6 L 20.582031 27.375 L 6.2304688 44 L 9.4101562 44 L 21.986328 29.421875 L 31.986328 44 L 44 44 L 28.681641 21.669922 L 42.199219 6 L 39.029297 6 L 27.275391 19.617188 L 17.933594 6 L 5.9199219 6 z M 9.7167969 8 L 16.880859 8 L 40.203125 42 L 33.039062 42 L 9.7167969 8 z" />
      </svg>
    </a>
  </div>
</div>

<script>
let delay = 5;
let downloadStarted = false;
let useProxy = false;

async function countdown() {
  document.getElementById('counter').textContent = delay;
  if(--delay === 0) {
    await startDownload();
  }
  else setTimeout(countdown, 1000);
}

async function startDownload() {
  try {
    // Check connectivity and get best download method
    if (window.downloadDetector) {
      try {
        const results = await window.downloadDetector.getBestDownloadMethod();
        
        if (results.recommended_method === 'proxy') {
          // User prefers proxy - use it directly
          console.log('Using proxy download (user preference)');
          document.getElementById('proxy-notice').style.display = 'block';
          useProxy = true;
          // For PHP implementation, we'll redirect to the fallback endpoint
          window.location.href = '/<?php echo addslashes($file_path); ?>/proxy-download';
          return;
        }
      } catch (error) {
        console.log('Download method detection failed:', error);
      }
    }
    
    // Try regular R2 download
    console.log('Attempting R2 download...');
    const downloadUrl = '/<?php echo addslashes($file_path); ?>/direct-download';
    
    // Create hidden iframe for download
    const iframe = document.createElement('iframe');
    iframe.style.display = 'none';
    iframe.src = downloadUrl;
    
    // Handle iframe errors (indicates download failure)
    iframe.onerror = () => {
      console.log('R2 download failed via iframe error');
      if (window.downloadDetector) {
        window.downloadDetector.reportPresignedFailure();
      }
      fallbackToProxy();
    };
    
    // Set a timeout to detect if download doesn't start
    const failureTimeout = setTimeout(() => {
      console.log('R2 download timeout - assuming failure');
      if (window.downloadDetector) {
        window.downloadDetector.reportPresignedFailure();
      }
      fallbackToProxy();
    }, 15000); // 15 second timeout
    
    document.body.appendChild(iframe);
    
    // Success handling
    setTimeout(() => {
      clearTimeout(failureTimeout);
      checkDownloadStarted();
      
      // Report success for R2 downloads
      if (window.downloadDetector) {
        setTimeout(() => {
          window.downloadDetector.reportPresignedSuccess();
        }, 3000);
      }
    }, 2000);
    
    console.log('Download started with R2 method');
    
  } catch (error) {
    console.error('Error in startDownload:', error);
    fallbackToProxy();
  }
}

function fallbackToProxy() {
  if (useProxy) {
    console.log('Already using proxy, no further fallback available');
    return;
  }
  
  console.log('Falling back to proxy download...');
  document.getElementById('proxy-notice').style.display = 'block';
  
  // Use direct navigation for proxy fallback to ensure it works
  window.location = '/<?php echo addslashes($file_path); ?>/proxy-download';
}





function checkDownloadStarted() {
  // For modern browsers, we can use the Page Visibility API to detect if download started
  const startTime = Date.now();
  const checkInterval = setInterval(() => {
    const elapsed = Date.now() - startTime;
    
    // If we've waited 5 seconds and the page is still visible/active, 
    // assume download started successfully
    if (elapsed > 5000) {
      downloadStarted = true;
      clearInterval(checkInterval);
      showRedirectMessage();
    }
    
    // Also check if page visibility changed (might indicate download dialog)
    if (document.hidden || !document.hasFocus()) {
      downloadStarted = true;
      clearInterval(checkInterval);
      showRedirectMessage();
    }
  }, 1000);
  
  // Fallback: assume download started after 3 seconds
  setTimeout(() => {
    if (!downloadStarted) {
      downloadStarted = true;
      clearInterval(checkInterval);
      showRedirectMessage();
    }
  }, 3000);
}

function showRedirectMessage() {
  document.getElementById('download-box').style.display = 'none';
  document.getElementById('redirect-box').style.display = '';
  redirectCountdown();
}

let redirectDelay = 5;
function redirectCountdown() {
  document.getElementById('redirect-counter').textContent = redirectDelay;
  if(--redirectDelay === 0) {
    window.location = '<?php echo htmlspecialchars($parent_dir); ?>';
  } else {
    setTimeout(redirectCountdown, 1000);
  }
}

// Add visibility change listener to detect download dialog
document.addEventListener('visibilitychange', function() {
  if (document.hidden && !downloadStarted) {
    downloadStarted = true;
    setTimeout(showRedirectMessage, 1000);
  }
});

countdown();
</script>

<?php
$content = ob_get_clean();
$page_title = 'Download - ' . $file_name;
include 'layout.php';
?>