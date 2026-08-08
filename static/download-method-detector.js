/**
 * Download Method Detector - Simple Connectivity Testing
 * Tests basic connectivity to Bunny download domains without file access
 */

class DownloadMethodDetector {
    constructor() {
        this.CACHE_KEY = 'downloadMethodTest';
        this.CACHE_DURATION = 3600000; // 1 hour
        const configuredDomains = Array.isArray(window.BUNNY_DOWNLOAD_DOMAINS)
            ? window.BUNNY_DOWNLOAD_DOMAINS.filter(domain => typeof domain === 'string' && domain.trim() !== '')
            : [];

        this.BUNNY_DOMAINS = configuredDomains.length > 0
            ? configuredDomains
            : [window.location.hostname];
    }
    
    /**
     * Test basic connectivity to Bunny domains
     */
    async testDirectConnectivity() {
        let anyWorking = false;
        
        for (const domain of this.BUNNY_DOMAINS) {
            try {
                // Try a simple request to test connectivity
                // Note: 400/404 responses still mean the domain is reachable
                const response = await fetch(`https://${domain}/`, {
                    method: 'HEAD',
                    mode: 'no-cors',
                    signal: AbortSignal.timeout(5000)
                });
                
                // With no-cors, any response (including errors) means domain is reachable
                // The key is that we don't get a network error (DNS/connection failure)
                anyWorking = true;
                console.log(`✅ Bunny domain ${domain} is reachable (connectivity confirmed)`);
                break;
                
            } catch (error) {
                // Only true network errors (DNS, connection timeout, etc.) end up here
                console.log(`❌ Bunny domain ${domain} failed:`, error.message);
                continue;
            }
        }
        
        return anyWorking;
    }
    
    /**
     * Test proxy availability
     */
    async testProxyAvailability() {
        try {
            const response = await fetch('/beyond2lte/16/recovery/recovery.img/proxy-download', { 
                method: 'HEAD',
                signal: AbortSignal.timeout(5000)
            });
            return response.ok;
        } catch (error) {
            console.log('Proxy test failed:', error);
            return false;
        }
    }
    
    /**
     * Get cached test results
     */
    getCachedResults() {
        try {
            const cached = localStorage.getItem(this.CACHE_KEY);
            if (!cached) return null;
            
            const data = JSON.parse(cached);
            if (Date.now() - data.timestamp > this.CACHE_DURATION) {
                localStorage.removeItem(this.CACHE_KEY);
                return null;
            }
            
            return data;
        } catch {
            return null;
        }
    }
    
    /**
     * Cache test results
     */
    setCachedResults(results) {
        try {
            localStorage.setItem(this.CACHE_KEY, JSON.stringify({
                ...results,
                timestamp: Date.now()
            }));
        } catch (error) {
            console.log('Failed to cache results:', error);
        }
    }
    
    /**
     * Run connectivity tests
     */
    async runConnectivityTests() {
        console.log('Testing download method connectivity...');
        console.log('ℹ️ Note: 400/404 HTTP errors are normal and expected - they indicate the domain is reachable');
        
        const [directConnectivity, proxyAvailability] = await Promise.all([
            this.testDirectConnectivity(),
            this.testProxyAvailability()
        ]);
        
        const results = {
            direct_available: directConnectivity,
            proxy_available: proxyAvailability,
            recommended_method: 'direct',
            test_timestamp: Date.now()
        };
        
        // Determine best method
        if (directConnectivity && proxyAvailability) {
            results.recommended_method = 'direct'; // Prefer faster option
        } else if (directConnectivity) {
            results.recommended_method = 'direct';
        } else if (proxyAvailability) {
            results.recommended_method = 'proxy';
        } else {
            results.recommended_method = 'direct';
        }
        
        console.log('Connectivity test results:', results);
        
        // Cache results
        this.setCachedResults(results);
        await this.setServerPreference(results.recommended_method);
        
        return results;
    }
    
    /**
     * Get best download method
     */
    async getBestDownloadMethod() {
        // Check cache first
        let results = this.getCachedResults();
        
        if (!results) {
            // Run fresh tests
            results = await this.runConnectivityTests();
        }
        
        return results;
    }
    
    /**
     * Set server preference
     */
    async setServerPreference(method) {
        try {
            await fetch('/api/download-prefs/set', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ method })
            });
        } catch (error) {
            console.log('Failed to set server preference:', error);
        }
    }
    
    /**
     * Force retest
     */
    async forceRetest() {
        localStorage.removeItem(this.CACHE_KEY);
        return await this.runConnectivityTests();
    }
    
    /**
     * Get download URL based on best method
     */
    async getDownloadUrl(folder, filename) {
        const results = await this.getBestDownloadMethod();
        const basePath = `/${folder}/${filename}`;
        
        return results.recommended_method === 'proxy' 
            ? `${basePath}/proxy-download`
            : `${basePath}/download`;
    }
    
    /**
     * Optimize download links on page
     */
    async optimizeDownloadLinks() {
        const results = await this.getBestDownloadMethod();
        
        if (results.recommended_method === 'proxy') {
            const downloadLinks = document.querySelectorAll('a[href*="/download"]:not([href*="/proxy-download"])');
            downloadLinks.forEach(link => {
                const href = link.getAttribute('href');
                if (href) {
                    link.setAttribute('href', href.replace('/download', '/proxy-download'));
                    
                    if (!link.querySelector('.proxy-indicator')) {
                        const indicator = document.createElement('span');
                        indicator.className = 'proxy-indicator text-xs text-blue-400 ml-2';
                        indicator.textContent = '(proxy)';
                        link.appendChild(indicator);
                    }
                }
            });
        }
        
        return results;
    }
    
    /**
     * Report actual download failure for learning
     */
    reportDownloadFailure(method) {
        if (method === 'direct') {
            console.log('Direct download failed, updating preference...');
            // Clear cache to force re-test, and set proxy preference
            localStorage.removeItem(this.CACHE_KEY);
            this.setServerPreference('proxy');
        }
    }

    reportDirectSuccess() {
        this.setCookie('download_method_preference', '', -1);
    }

    reportDirectFailure() {
        this.setCookie('download_method_preference', 'fallback', 24 * 60 * 60);
    }
}

// Global instance
window.downloadDetector = new DownloadMethodDetector();

// Auto-optimize download links when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.downloadDetector.optimizeDownloadLinks();
});
