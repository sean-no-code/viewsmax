(function () {
    console.log("ViewsMax Tracker: Initializing...");

    // --- Configuration ---
    // Dynamically detect API URL from this script's own location
    const API_BASE_URL = "https://api.viewsmax.com";

    // --- Helpers ---
    function getMetaContent(name) {
        const meta = document.querySelector(`meta[name="${name}"]`);
        return meta ? meta.getAttribute("content") : null;
    }

    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return decodeURIComponent(parts.pop().split(';').shift());
    }

    function setCookie(name, value, days) {
        let expires = "";
        if (days) {
            const date = new Date();
            date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
            expires = "; expires=" + date.toUTCString();
        }

        let domain = window.location.hostname;
        const parts = domain.split('.');

        // Regex to skip IP addresses
        const isIp = /^\d{1,3}(\.\d{1,3}){3}$/.test(domain);

        // Try to set cookie on broadest possible domain
        if (parts.length > 1 && !isIp) {
            const testName = 'vm_root_check';
            // Start from the last 2 parts (e.g., 'co.uk' or 'site.com')
            for (let i = parts.length - 2; i >= 0; i--) {
                const d = parts.slice(i).join('.');
                // Try setting a test cookie
                document.cookie = `${testName}=1; domain=.${d}; path=/; SameSite=Lax`;

                // If the cookie was set successfully, we found the root domain
                if (document.cookie.indexOf(`${testName}=1`) !== -1) {
                    domain = d;
                    // Clean up test cookie
                    document.cookie = `${testName}=; domain=.${d}; path=/; expires=Thu, 01 Jan 1970 00:00:01 GMT`;
                    break;
                }
            }
        }

        const domainAttribute = domain === 'localhost' ? '' : `; domain=.${domain}`;

        // FIX 1: Use encodeURIComponent for value safety
        // FIX 2: Add Secure flag (check if page is HTTPS)
        const secureFlag = window.location.protocol === 'https:' ? '; Secure' : '';

        document.cookie = name + "=" + (encodeURIComponent(value) || "") + expires + "; path=/" + domainAttribute + "; SameSite=Lax" + secureFlag;
    }

    function getStorage(key) {
        return localStorage.getItem(key);
    }

    function setStorage(key, value) {
        localStorage.setItem(key, value);
    }

    // Origin context sent with every click/conversion so the backend can tie the
    // visit to a platform (referrer/UTM) beyond the ?trk= attribution key.
    function getAttributionContext() {
        const p = new URLSearchParams(window.location.search);
        return {
            referrer: document.referrer || "",
            landing_url: window.location.href,
            utm_source: p.get("utm_source"),
            utm_medium: p.get("utm_medium"),
            utm_campaign: p.get("utm_campaign"),
            utm_term: p.get("utm_term"),
            utm_content: p.get("utm_content")
        };
    }

    // --- Core Logic ---

    // 1. Identify User (Public ID from Meta)
    const publicId = getMetaContent("viewsmax-user");
    if (!publicId) {
        console.warn("ViewsMax: No 'viewsmax-user' meta tag found. Tracking disabled.");
        return;
    }

    // 2. Identify Visitor (UUID)
    let visitorId = getCookie("vm_visitor_id") || getStorage("vm_visitor_id");

    // 3. Check for Click (Entry)
    const urlParams = new URLSearchParams(window.location.search);
    const trkHash = urlParams.get("trk");

    // Click first (it may mint the visitor id), THEN the pageview beacon —
    // firing both at once with no visitor id would create two visitors.
    (async function () {
        if (trkHash) {
            console.log("ViewsMax: Tracking Parameter Detected:", trkHash);

            // De-duplication: SessionStorage
            const sessionKey = `vm_clicked_${trkHash}`;
            if (sessionStorage.getItem(sessionKey)) {
                console.log("ViewsMax: Click already logged this session.");
            } else {
                // Track Click
                await trackClick(trkHash);
            }
        }

        // Every page load beacons a pageview — this is what powers Visitors
        // and the Sources/Referrers acquisition tables.
        trackPageview();
    })();

    // 4. Check for Conversion (Goal)
    const storedGoals = getStorage("vm_goal_urls");
    let goalUrls = [];
    try {
        goalUrls = storedGoals ? JSON.parse(storedGoals) : [];
    } catch (e) {
        console.error("ViewsMax: Failed to parse stored goals", e);
        return;
    }

    if (goalUrls && goalUrls.length > 0) {
        const currentPathname = window.location.pathname;
        const currentPath = window.location.href;

        // Check strict match for ANY goal path
        for (const goalUrl of goalUrls) {
            // Normalize Goal Path
            let goalPathname = goalUrl;
            try {
                // If goalUrl is full URL, extract path
                if (goalUrl.startsWith('http')) {
                    goalPathname = new URL(goalUrl).pathname;
                } else if (!goalUrl.startsWith('/')) {
                    // Ensure leading slash if it's a relative path fragment 
                    // (though backend usually sends relative or absolute)
                    goalPathname = '/' + goalUrl;
                }
            } catch (err) {
                console.warn("ViewsMax: Could not parse goal URL", goalUrl);
            }

            // Strict Equality Check on Pathname
            if (currentPathname === goalPathname) {
                console.log("ViewsMax: Conversion Goal Matched!", goalUrl);
                trackConversion(goalUrl);
                break;
            }
        }
    }

    // --- API Calls ---

    async function trackPageview() {
        try {
            const params = new URLSearchParams(window.location.search);
            const response = await fetch(`${API_BASE_URL}/api/track/pageview`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    public_id: publicId,
                    visitor_id: visitorId || null,
                    url: window.location.href,
                    referrer: document.referrer || null,
                    utm_source: params.get("utm_source") || null
                })
            });
            if (!response.ok) return;
            const data = await response.json();
            if (data.visitor_id) {
                visitorId = data.visitor_id;
                setCookie("vm_visitor_id", visitorId, 30);
                setStorage("vm_visitor_id", visitorId);
            }
        } catch (e) {
            console.error("ViewsMax: Pageview tracking failed", e);
        }
    }

    async function trackClick(hash) {
        try {
            const payload = Object.assign({
                trk: hash,
                visitor_id: visitorId, // Send if we have it (return visitor)
                public_id: publicId
            }, getAttributionContext());

            const response = await fetch(`${API_BASE_URL}/api/track/click`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload)
            });

            const data = await response.json();

            if (data.visitor_id) {
                visitorId = data.visitor_id;
                setCookie("vm_visitor_id", visitorId, 30);
                setStorage("vm_visitor_id", visitorId);
            }

            if (data.goals) {
                // Store the goal URLs for this user
                setStorage("vm_goal_urls", JSON.stringify(data.goals));
            }

            // Mark session as clicked
            sessionStorage.setItem(`vm_clicked_${hash}`, "true");
            console.log("ViewsMax: Click Logged.");

        } catch (err) {
            console.error("ViewsMax: Track Click Failed", err);
        }
    }

    async function trackConversion(goalUrlFragment) {
        // De-dupe conversions?
        // Maybe only once per session?
        if (sessionStorage.getItem(`vm_converted_${goalUrlFragment}`)) {
            console.log("ViewsMax: Conversion already logged this session.");
            return;
        }

        try {
            const payload = Object.assign({
                visitor_id: visitorId,
                current_url: window.location.href,
                trk: trkHash, // Optional: Pass current trk if present (Direct Link Backfill case)
                public_id: publicId
            }, getAttributionContext());

            const response = await fetch(`${API_BASE_URL}/api/track/conversion`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload)
            });

            const data = await response.json();

            if (data.status === 'converted' || data.status === 'already_converted') {
                console.log("ViewsMax: Conversion Recorded! Status:", data.status);
                sessionStorage.setItem(`vm_converted_${goalUrlFragment}`, "true");
            }

        } catch (err) {
            console.error("ViewsMax: Track Conversion Failed", err);
        }
    }

})();
