/**
 * Download Method Detector - Simple Connectivity Testing
 * Tests basic connectivity to CloudFlare domains without file access
 */

class DownloadMethodDetector {
    constructor() {
        this.CACHE_KEY = 'downloadMethodTest';
        this.CACHE_DURATION = 3600000; // 1 hour
        this.CF_DOMAINS = [
            'e47b8ab6487c7271956f83661e6ac050.r2.cloudflarestorage.com',
            'pub-3626123a908346a7a8be8d9295f44e26.r2.dev',
            'evo-dl.ndts-storage.uk'
        ];
    }
    
    /**
     * Test basic connectivity to CloudFlare domains
     */
    async testCloudFlareConnectivity() {
        let anyWorking = false;
        
        for (const domain of this.CF_DOMAINS) {
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
                console.log(`✅ CloudFlare domain ${domain} is reachable (connectivity confirmed)`);
                break;
                
            } catch (error) {
                // Only true network errors (DNS, connection timeout, etc.) end up here
                console.log(`❌ CloudFlare domain ${domain} failed:`, error.message);
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
        
        const [cfConnectivity, proxyAvailability] = await Promise.all([
            this.testCloudFlareConnectivity(),
            this.testProxyAvailability()
        ]);
        
        const results = {
            presigned_available: cfConnectivity,
            proxy_available: proxyAvailability,
            recommended_method: 'presigned', // Default
            test_timestamp: Date.now()
        };
        
        // Determine best method
        if (cfConnectivity && proxyAvailability) {
            results.recommended_method = 'presigned'; // Prefer faster option
        } else if (cfConnectivity) {
            results.recommended_method = 'presigned';
        } else if (proxyAvailability) {
            results.recommended_method = 'proxy';
        } else {
            results.recommended_method = 'presigned'; // Fallback
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
        if (method === 'presigned') {
            console.log('Presigned download failed, updating preference...');
            // Clear cache to force re-test, and set proxy preference
            localStorage.removeItem(this.CACHE_KEY);
            this.setServerPreference('proxy');
        }
    }
}

// Global instance
window.downloadDetector = new DownloadMethodDetector();

// Auto-optimize download links when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    window.downloadDetector.optimizeDownloadLinks();
});
