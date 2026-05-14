<!DOCTYPE html>
<html lang="en">
<head>

    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=G-YDQ5FJEEM2"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    // Set default consent to denied (will be updated based on user choice)
    gtag('consent', 'default', {
        'analytics_storage': 'denied'
    });

    gtag('config', 'G-YDQ5FJEEM2', {
        'anonymize_ip': true
    });
    </script>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'Evolution X - Download Server'; ?></title>
    <link rel="stylesheet" href="static/tailwind.css">
    <script defer src="static/alpine.min.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <script>
        // Simple downloadDetector implementation
        window.downloadDetector = {
            async testDomain(domain, timeout = 5000) {
                return new Promise(resolve => {
                    const img = new Image();
                    const timer = setTimeout(() => {
                        resolve(false);
                    }, timeout);
                    
                    img.onload = img.onerror = () => {
                        clearTimeout(timer);
                        resolve(true);
                    };
                    
                    img.src = `https://${domain}/favicon.ico?t=${Date.now()}`;
                });
            },
            
            async getBestDownloadMethod() {
                // Check user preference first
                const preference = this.getCookie('download_method_preference');
                if (preference === 'fallback') {
                    return {
                        recommended_method: 'proxy',
                        presigned_available: false,
                        reason: 'user_preference'
                    };
                }
                
                // Test primary CloudFlare domains
                const domains = [
                    'pub-a81259bbcab24b7697844a1f30bf4cde.r2.dev',
                    'cloudflare.com'
                ];
                
                let presignedAvailable = false;
                for (const domain of domains) {
                    if (await this.testDomain(domain, 3000)) {
                        presignedAvailable = true;
                        break;
                    }
                }
                
                return {
                    recommended_method: presignedAvailable ? 'presigned' : 'proxy',
                    presigned_available: presignedAvailable,
                    reason: presignedAvailable ? 'connectivity_ok' : 'connectivity_restricted'
                };
            },
            
            reportPresignedSuccess() {
                // Remove fallback preference on success
                this.setCookie('download_method_preference', '', -1);
            },
            
            reportPresignedFailure() {
                // Set fallback preference for future downloads
                this.setCookie('download_method_preference', 'fallback', 24 * 60 * 60);
            },
            
            getCookie(name) {
                const value = `; ${document.cookie}`;
                const parts = value.split(`; ${name}=`);
                if (parts.length === 2) return parts.pop().split(';').shift();
                return null;
            },
            
            setCookie(name, value, maxAge) {
                const cookie = `${name}=${value}; path=/; max-age=${maxAge}`;
                document.cookie = cookie;
            }
        };
    </script>
    <link rel="stylesheet" href="static/custom.css">
    <link rel="icon" href="static/favicon.ico" type="image/x-icon">
</head>
<body class="bg-[#040214] text-white font-prodsans tracking-wide h-screen">
    <nav class="bg-transparent shadow mb-6">
        <div class="z-50 mx-8 my-3 mb-0 flex items-center justify-between py-4 uppercase lg:mx-8 lg:my-7">
            <a href="/" class="text-white text-xl">
                <svg width="39" height="54" viewBox="0 0 39 54" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M37.0867 2H4.60425L13.0256 10.3138H35.4826L37.0867 2Z" fill="white" />
                    <path d="M34.2794 20.2111H5.4061L3 28.9208L28.6651 50.695L31.0712 40.7976L17.4366 28.9208H36.6855L34.2794 20.2111Z" fill="white" />
                </svg>
            </a>
            <div class="fixed left-0 right-0 z-50 flex h-full flex-col items-center justify-center gap-8 rounded-2xl bg-transparent text-2xl text-[#A9A9A9] duration-300 ease-in-out md:static md:flex-row md:bg-transparent md:pl-0 md:pt-0 md:text-[1rem] md:backdrop-blur-0 lg:gap-14 top-[-2500px]">
                <a href="/stats" class="relative transition-colors duration-300 hover:text-[#0060ff]">STATS</a>
                <a href="/health" class="relative transition-colors duration-300 hover:text-[#0060ff]">HEALTH</a>
            </div>
        </div>
    </nav>
    
    <main class="container mx-auto px-4">
        <?php echo $content; ?>
    </main>
    
    <footer class="m-6 bg-transparent mt-12 py-6 border-t border-white/50 sticky top-[100vh]">
        <div class="mx-auto px-4 flex flex-col md:flex-row justify-between items-center text-gray-700 text-sm">
            <div class="mb-2 md:mb-0">
                <svg xmlns="http://www.w3.org/2000/svg" width="300" height="85" viewBox="0 0 495 85" fill="none">
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
            <div class="my-5 flex h-12 justify-evenly gap-4">
                <a href="https://discord.com/invite/evolution-x-670512508871639041" target="_blank" rel="noreferrer">
                    <svg xmlns="http://www.w3.org/2000/svg" width="51" height="51" viewBox="0 0 51 51" fill="none">
                        <rect width="51" height="51" rx="25.5" fill="white" />
                        <path d="M35.8838 15.9228C33.9837 15.0705 31.9265 14.4519 29.7836 14.0945C29.7459 14.095 29.71 14.1098 29.6836 14.1357C29.4265 14.5894 29.1265 15.1805 28.9265 15.6341C26.6535 15.3044 24.3421 15.3044 22.0691 15.6341C21.8691 15.1667 21.5691 14.5894 21.2977 14.1357C21.2834 14.1082 21.2405 14.0945 21.1977 14.0945C19.0548 14.4519 17.0118 15.0705 15.0975 15.9228C15.0832 15.9228 15.0689 15.9365 15.0546 15.9503C11.1688 21.5451 10.0974 26.9886 10.626 32.3772C10.626 32.4047 10.6402 32.4322 10.6688 32.446C13.2403 34.2605 15.7118 35.3602 18.1547 36.0888C18.1976 36.1025 18.2404 36.0888 18.2547 36.0613C18.8262 35.3052 19.3405 34.5079 19.7833 33.6694C19.8119 33.6144 19.7833 33.5594 19.7262 33.5457C18.9119 33.2433 18.1404 32.8859 17.3833 32.4735C17.3261 32.446 17.3261 32.3635 17.369 32.3223C17.5261 32.2123 17.6833 32.0886 17.8404 31.9786C17.869 31.9511 17.9119 31.9511 17.9404 31.9649C22.8549 34.123 28.155 34.123 33.0123 31.9649C33.0409 31.9511 33.0837 31.9511 33.1123 31.9786C33.2694 32.1023 33.4266 32.2123 33.5837 32.336C33.6409 32.3773 33.6409 32.4597 33.5694 32.4872C32.8266 32.9134 32.0408 33.257 31.2265 33.5594C31.1694 33.5732 31.1551 33.6419 31.1694 33.6832C31.6265 34.5217 32.1408 35.319 32.698 36.075C32.7408 36.0888 32.7837 36.1025 32.8266 36.0888C35.2838 35.3602 37.7553 34.2605 40.3268 32.446C40.3553 32.4322 40.3696 32.4047 40.3696 32.3772C40.9982 26.1501 39.3267 20.7478 35.9409 15.9503C35.9266 15.9365 35.9124 15.9228 35.8838 15.9228ZM20.5262 29.0919C19.0548 29.0919 17.8262 27.7859 17.8262 26.1776C17.8262 24.5693 19.0262 23.2634 20.5262 23.2634C22.0406 23.2634 23.2406 24.583 23.2263 26.1776C23.2263 27.7859 22.0263 29.0919 20.5262 29.0919ZM30.4836 29.0919C29.0122 29.0919 27.7836 27.7859 27.7836 26.1776C27.7836 24.5693 28.9836 23.2634 30.4836 23.2634C31.998 23.2634 33.198 24.583 33.1837 26.1776C33.1837 27.7859 31.998 29.0919 30.4836 29.0919Z" fill="black" />
                    </svg>
                </a>
                <a href="https://github.com/Evolution-X" target="_blank" rel="noreferrer">
                    <svg xmlns="http://www.w3.org/2000/svg" width="51" height="51" viewBox="0 0 51 51" fill="none">
                        <rect width="51" height="51" rx="25.5" fill="white" />
                        <path d="M19.2803 14.4123C20.2194 14.7292 21.1126 15.1689 21.9365 15.7198C23.1009 15.4218 24.2984 15.2722 25.5003 15.2748C26.7415 15.2748 27.939 15.4298 29.0615 15.7185C29.8852 15.1681 30.7779 14.7289 31.7165 14.4123C32.5878 14.116 33.829 13.636 34.5665 14.4523C35.0665 15.0073 35.1915 15.9373 35.2803 16.6473C35.3803 17.4398 35.404 18.4723 35.1415 19.4973C36.1453 20.7935 36.7503 22.3398 36.7503 24.0248C36.7503 26.5773 35.3678 28.7935 33.3215 30.3285C32.3363 31.0572 31.2442 31.629 30.084 32.0235C30.3515 32.636 30.5003 33.3135 30.5003 34.0248V37.7748C30.5003 38.1063 30.3686 38.4243 30.1342 38.6587C29.8997 38.8931 29.5818 39.0248 29.2503 39.0248H21.7503C21.4188 39.0248 21.1008 38.8931 20.8664 38.6587C20.632 38.4243 20.5003 38.1063 20.5003 37.7748V36.536C19.3065 36.6823 18.3053 36.5523 17.454 36.191C16.564 35.8135 15.944 35.2285 15.4778 34.6685C15.0353 34.1385 14.5528 32.9435 13.8553 32.711C13.6995 32.6592 13.5555 32.5771 13.4314 32.4696C13.3073 32.3621 13.2056 32.2311 13.1322 32.0843C12.9838 31.7878 12.9593 31.4444 13.064 31.1298C13.1688 30.8152 13.3942 30.5551 13.6908 30.4067C13.9873 30.2583 14.3307 30.2338 14.6453 30.3385C15.4778 30.616 16.0203 31.216 16.3915 31.6985C16.9915 32.4735 17.479 33.486 18.429 33.8898C18.8203 34.056 19.394 34.1648 20.2915 34.0423L20.5003 33.9998C20.5032 33.3196 20.6448 32.6471 20.9165 32.0235C19.7564 31.629 18.6643 31.0572 17.679 30.3285C15.6328 28.7935 14.2503 26.5785 14.2503 24.0248C14.2503 22.3423 14.854 20.7973 15.8553 19.5023C15.5928 18.4773 15.6153 17.4423 15.7153 16.6485L15.7215 16.601C15.8128 15.8735 15.919 15.0173 16.429 14.4523C17.1665 13.636 18.409 14.1173 19.279 14.4135L19.2803 14.4123Z" fill="black" />
                    </svg>
                </a>
                <a href="https://x.com/EvolutionXROM" target="_blank" rel="noreferrer" class="rounded-full bg-white p-2 w-[51px] h-[51px]">
                    <svg xmlns="http://www.w3.org/2000/svg" x="0px" y="0px" width="36px" height="36px" viewBox="0 0 50 50">
                        <path d="M 5.9199219 6 L 20.582031 27.375 L 6.2304688 44 L 9.4101562 44 L 21.986328 29.421875 L 31.986328 44 L 44 44 L 28.681641 21.669922 L 42.199219 6 L 39.029297 6 L 27.275391 19.617188 L 17.933594 6 L 5.9199219 6 z M 9.7167969 8 L 16.880859 8 L 40.203125 42 L 33.039062 42 L 9.7167969 8 z" />
                    </svg>
                </a>
                <a href="https://opencollective.com/evolution-x" target="_blank" rel="noreferrer" data-cmp-ab="2">
                    <svg xmlns="http://www.w3.org/2000/svg" width="51" height="51" viewBox="0 0 51 51" fill="none">
                        <rect width="51" height="51" rx="25.5" fill="white"></rect>
                        <path d="M25.5 32.952C30.8513 32.952 35.1874 28.6159 35.1874 23.2646C35.1874 17.9132 30.8513 13.5771 25.5 13.5771C20.1486 13.5771 15.8125 17.9132 15.8125 23.2646C15.8125 28.6159 20.1486 32.952 25.5 32.952ZM24.466 18.1135V17.331C24.466 16.9025 24.8107 16.5579 25.2391 16.5579H25.7561C26.1846 16.5579 26.5293 16.9025 26.5293 17.331V18.1228C27.2512 18.16 27.9498 18.4069 28.5319 18.84C28.7928 19.031 28.8207 19.4129 28.5878 19.6364L27.8287 20.3583C27.6517 20.5307 27.3862 20.5353 27.1766 20.4049C26.9251 20.2466 26.6457 20.1674 26.3476 20.1674H24.5359C24.1167 20.1674 23.7767 20.5493 23.7767 21.0197C23.7767 21.4016 24.0096 21.7416 24.3403 21.8394L27.2418 22.7103C28.4388 23.069 29.2771 24.2193 29.2771 25.5094C29.2771 27.093 28.0476 28.3737 26.5246 28.4157V29.1981C26.5246 29.6266 26.1799 29.9712 25.7515 29.9712H25.2345C24.806 29.9712 24.4614 29.6266 24.4614 29.1981V28.4063C23.7395 28.3691 23.0409 28.1222 22.4587 27.6891C22.1979 27.4982 22.1699 27.1162 22.4028 26.8927L23.1619 26.1708C23.3389 25.9985 23.6044 25.9938 23.814 26.1242C24.0655 26.2826 24.3449 26.3617 24.643 26.3617H26.4547C26.8739 26.3617 27.2139 25.9798 27.2139 25.5094C27.2139 25.1275 26.981 24.7875 26.6503 24.6897L23.7488 23.8188C22.5518 23.4602 21.7135 22.3098 21.7135 21.0197C21.7181 19.4362 22.943 18.1554 24.466 18.1135ZM35.9326 29.9712H34.4189C33.5061 31.1822 32.3417 32.1928 31.019 32.952H33.9904C34.2373 32.952 34.4375 33.1196 34.4375 33.3246V34.0698C34.4375 34.2747 34.2373 34.4424 33.9904 34.4424H17.0048C16.758 34.4424 16.5577 34.2747 16.5577 34.0698V33.3246C16.5577 33.1196 16.758 32.952 17.0048 32.952H19.9763C18.6536 32.1928 17.4939 31.1822 16.5764 29.9712H15.0674C14.243 29.9712 13.577 30.6372 13.577 31.4616V35.9327C13.577 36.7571 14.243 37.4231 15.0674 37.4231H35.9326C36.7569 37.4231 37.4229 36.7571 37.4229 35.9327V31.4616C37.4229 30.6372 36.7569 29.9712 35.9326 29.9712Z" fill="#080808"></path>
                    </svg>
                </a>
            </div>
        </div>
        <div class="mx-auto px-4 flex flex-col md:flex-row justify-between items-center text-white text-lg">
            <div class="text-[#0060ff]">
                #KeepEvolving
            </div>
            <div>
                Developed by <a href="https://github.com/AidanWarner97" target="_blank" rel="noreferrer" class="underline font-prodsansbold">Aidan Warner</a>
            </div>
        </div>
    </footer>
    
    <!-- Cookie Banner -->
    <div id="cookieBanner" class="hidden fixed bottom-0 left-0 right-0 z-50 bg-[#040214] border-t border-[#0060ff]/30 shadow-2xl p-6">
        <div class="container mx-auto max-w-6xl">
            <div class="flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4">
                <div class="flex-1">
                    <h3 class="text-white font-prodsansbold text-lg mb-2">🍪 We use cookies</h3>
                    <p class="text-gray-300 text-sm leading-relaxed">
                        This site uses essential cookies for functionality and analytics cookies to help us improve our service. 
                        <br>
                        <button onclick="showCookieDetails()" class="text-[#0060ff] hover:text-[#0080ff] underline transition-colors duration-200 font-medium">
                            View details
                        </button>
                        about which cookies we use and why.
                    </p>
                </div>
                <div class="flex flex-col sm:flex-row gap-3 min-w-fit">
                    <button onclick="acceptAllCookies()" class="px-6 py-2 bg-[#0060ff] text-white font-prodsansbold rounded-lg hover:bg-[#0050dd] transition-colors duration-200">
                        Accept All
                    </button>
                    <button onclick="acceptEssentialOnly()" class="px-6 py-2 bg-transparent border border-white/30 text-white font-prodsansbold rounded-lg hover:border-[#0060ff] hover:text-[#0060ff] transition-colors duration-200">
                        Essential Only
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Cookie Details Modal -->
    <div id="cookieModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/70 backdrop-blur-sm">
        <div class="bg-[#040214] border border-[#0060ff]/30 rounded-xl shadow-2xl max-w-2xl w-full mx-4 max-h-[80vh] overflow-y-auto">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h2 class="text-white font-prodsansbold text-xl">Cookie Information</h2>
                    <button onclick="hideCookieDetails()" class="text-gray-400 hover:text-white transition-colors duration-200">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <div class="bg-[#0a0a1a] rounded-lg p-4 border border-[#0060ff]/20">
                        <h3 class="text-[#0060ff] font-prodsansbold text-lg mb-2">Essential Cookies</h3>
                        <p class="text-gray-300 text-sm mb-3">These cookies are necessary for the website to function properly and cannot be disabled.</p>
                        
                        <div class="space-y-3">
                            <div class="border-l-2 border-[#0060ff]/50 pl-3">
                                <h4 class="text-white font-prodsansbold text-sm">cookie_consent</h4>
                                <p class="text-gray-400 text-xs">Remembers that you've acknowledged this cookie notice</p>
                                <p class="text-gray-500 text-xs mt-1">Duration: 365 days</p>
                            </div>
                            
                            <div class="border-l-2 border-[#0060ff]/50 pl-3">
                                <h4 class="text-white font-prodsansbold text-sm">download_method_preference</h4>
                                <p class="text-gray-400 text-xs">Stores your preferred download method (direct/proxy) for better performance and reliability</p>
                                <p class="text-gray-500 text-xs mt-1">Duration: 24 hours</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-[#1a0a1a] rounded-lg p-4 border border-purple-500/20">
                        <h3 class="text-purple-400 font-prodsansbold text-lg mb-2">Analytics Cookies</h3>
                        <p class="text-gray-300 text-sm mb-3">These cookies help us understand how visitors interact with our website so we can improve our service.</p>
                        
                        <div class="space-y-3">
                            <div class="border-l-2 border-purple-500/50 pl-3">
                                <h4 class="text-white font-prodsansbold text-sm">Google Analytics (_ga, _ga_*, _gid)</h4>
                                <p class="text-gray-400 text-xs">Tracks website usage, page views, and user behavior to help us improve the site experience</p>
                                <p class="text-gray-500 text-xs mt-1">Duration: 2 years (_ga), 24 hours (_gid), 90 days (_ga_*)</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-[#1a1a0a] rounded-lg p-4 border border-yellow-500/20">
                        <h3 class="text-yellow-400 font-prodsansbold text-lg mb-2">Third-Party Cookies</h3>
                        <p class="text-gray-300 text-sm mb-3">These cookies are set by external services we use and are not under our direct control.</p>
                        
                        <div class="space-y-3">
                            <div class="border-l-2 border-yellow-500/50 pl-3">
                                <h4 class="text-white font-prodsansbold text-sm">cf_clearance</h4>
                                <p class="text-gray-400 text-xs">Set by Cloudflare for DDoS protection and bot detection. Required for site access.</p>
                                <p class="text-gray-500 text-xs mt-1">Duration: Variable (managed by Cloudflare)</p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-[#0a1a0a] rounded-lg p-4 border border-green-500/20">
                        <h3 class="text-green-400 font-prodsansbold text-lg mb-2">Privacy Notice</h3>
                        <p class="text-gray-300 text-sm">
                            We respect your privacy. Download statistics are recorded with anonymized IP addresses. 
                            Google Analytics data is processed according to Google's privacy policy and we use it solely 
                            to improve our service and understand usage patterns.
                        </p>
                    </div>
                </div>
                
                <div class="mt-6 pt-4 border-t border-[#0060ff]/20">
                    <p class="text-gray-400 text-xs mb-3">
                        For more information about cookies and how to control them in your browser, visit:
                    </p>
                    <a href="https://www.allaboutcookies.org/" target="_blank" rel="noopener noreferrer" 
                       class="text-[#0060ff] hover:text-[#0080ff] underline text-sm transition-colors duration-200">
                        allaboutcookies.org
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Cookie Banner Script -->
    <script>
        // Check if user has already made a cookie choice
        function getCookie(name) {
            const value = `; ${document.cookie}`;
            const parts = value.split(`; ${name}=`);
            if (parts.length === 2) return parts.pop().split(';').shift();
            return null;
        }
        
        function setCookie(name, value, days) {
            const expires = new Date();
            expires.setTime(expires.getTime() + (days * 24 * 60 * 60 * 1000));
            document.cookie = `${name}=${value}; expires=${expires.toUTCString()}; path=/; SameSite=Lax`;
        }
        
        function hideCookieBanner() {
            document.getElementById('cookieBanner').classList.add('hidden');
        }
        
        function showCookieBanner() {
            document.getElementById('cookieBanner').classList.remove('hidden');
        }
        
        function showCookieDetails() {
            document.getElementById('cookieModal').classList.remove('hidden');
        }
        
        function hideCookieDetails() {
            document.getElementById('cookieModal').classList.add('hidden');
        }
        
        function acceptAllCookies() {
            setCookie('cookie_consent', 'all', 365);
            setCookie('analytics_consent', 'granted', 365);
            
            // Enable Google Analytics
            gtag('consent', 'update', {
                'analytics_storage': 'granted'
            });
            
            hideCookieBanner();
            hideCookieDetails(); // Also hide modal if it's open
            console.log('✅ All cookies accepted - Analytics enabled');
        }
        
        function acceptEssentialOnly() {
            setCookie('cookie_consent', 'essential', 365);
            setCookie('analytics_consent', 'denied', 365);
            
            // Disable Google Analytics
            gtag('consent', 'update', {
                'analytics_storage': 'denied'
            });
            
            hideCookieBanner();
            hideCookieDetails(); // Also hide modal if it's open
            console.log('✅ Essential cookies only - Analytics disabled');
        }
        
        // Close modal when clicking outside of it
        document.addEventListener('click', function(event) {
            const modal = document.getElementById('cookieModal');
            if (event.target === modal) {
                hideCookieDetails();
            }
        });
        
        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                hideCookieDetails();
            }
        });
        
        // Show banner if user hasn't made a consent choice
        document.addEventListener('DOMContentLoaded', function() {
            const consent = getCookie('cookie_consent');
            const analyticsConsent = getCookie('analytics_consent');
            
            if (!consent) {
                // Delay showing banner slightly for better UX
                setTimeout(showCookieBanner, 1000);
            } else {
                // Apply existing consent preferences
                if (analyticsConsent === 'granted') {
                    gtag('consent', 'update', {
                        'analytics_storage': 'granted'
                    });
                    console.log('🔄 Analytics consent restored from cookie');
                } else {
                    gtag('consent', 'update', {
                        'analytics_storage': 'denied'
                    });
                    console.log('🔄 Analytics disabled per saved preference');
                }
            }
        });
    </script>
</body>
</html>