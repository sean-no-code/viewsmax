import { PRIVACY_POLICY_VERSION } from "@/constants/policy";

const LOCAL_KEY_PREFIX = "tm_privacy_consent";

function getLocalKey(userId: string): string {
  return `${LOCAL_KEY_PREFIX}:${userId}`;
}

function getLocalConsent(userId: string): boolean {
  try {
    const raw = localStorage.getItem(getLocalKey(userId));
    if (!raw) return false;
    const parsed = JSON.parse(raw) as { policy_version: string; consented_at: number };
    return parsed.policy_version === PRIVACY_POLICY_VERSION;
  } catch (error) {
    console.debug('Error getting local consent:', error);
    return false;
  }
}

// Export function to check if local consent exists (without API calls)
export function hasLocalConsent(userId: string): boolean {
  return getLocalConsent(userId);
}

function setLocalConsent(userId: string): void {
  try {
    localStorage.setItem(
      getLocalKey(userId),
      JSON.stringify({ policy_version: PRIVACY_POLICY_VERSION, consented_at: Date.now() })
    );
  } catch (error) {
    console.debug('Error setting local consent:', error);
  }
}

export async function hasUserConsented(userId: string): Promise<boolean> {
  // Step 1: Check local storage first (fast check)
  if (getLocalConsent(userId)) {
    console.debug('Consent found in local storage');
    return true;
  }

  // Step 2: If no local storage data, check database via /check endpoint
  try {
    console.debug('No local consent found, checking database...');
    const apiBaseUrl = import.meta.env.VITE_API_BASE_URL;
    const consentUrl = `${apiBaseUrl}/api/user/consent/check`;
    
    
    // Get auth session for headers
    const authSession = localStorage.getItem('auth_session');
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
    };
    
    // Add authorization header if available
    if (authSession) {
      try {
        const session = JSON.parse(authSession);
        if (session.token) {
          headers['Authorization'] = `${session.token_type || 'Bearer'} ${session.token}`;
        }
      } catch (e) {
        console.warn('Could not parse auth session for consent check');
      }
    }
    
    const response = await fetch(consentUrl, {
      method: 'POST',
      headers,
      body: JSON.stringify({
        user_id: userId,
        policy_version: PRIVACY_POLICY_VERSION
      }),
    });

    if (!response.ok) {
      console.warn('Privacy consent check failed, using local fallback');
      return getLocalConsent(userId);
    }

    const data = await response.json();
    
    // Step 3: If database returns success: true or has_consented: true, add to local storage
    const hasConsented = data.success === true || data.has_consented === true;
    
    if (hasConsented) {
      console.debug('Consent found in database, adding to local storage');
      setLocalConsent(userId);
    }
    
    return hasConsented;
  } catch (error) {
    console.warn('Error checking privacy consent:', error);
    return getLocalConsent(userId);
  }
}

export async function recordUserConsent(userId: string): Promise<void> {
  // Check if consent is already present in local storage
  if (getLocalConsent(userId)) {
    console.debug('Consent already exists in local storage, no need to check database or record');
    return; // If consent is present in local storage, don't do /check or /record
  }
  
  // Add data to local storage as per requirement
  setLocalConsent(userId);
  
  // Only check database if consent was not present in local storage
  try {
    const apiBaseUrl = import.meta.env.VITE_API_BASE_URL;
    const checkUrl = `${apiBaseUrl}/api/user/consent/check`;
    
    // Get auth session for headers
    const authSession = localStorage.getItem('auth_session');
    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
    };
    
    // Add authorization header if available
    if (authSession) {
      try {
        const session = JSON.parse(authSession);
        if (session.token) {
          headers['Authorization'] = `${session.token_type || 'Bearer'} ${session.token}`;
        }
      } catch (e) {
        console.warn('Could not parse auth session for consent check');
      }
    }
    
    // Check if consent already exists in database
    const checkResponse = await fetch(checkUrl, {
      method: 'POST',
      headers,
      body: JSON.stringify({
        user_id: userId,
        policy_version: PRIVACY_POLICY_VERSION
      }),
    });

    if (checkResponse.ok) {
      const checkData = await checkResponse.json();
      const alreadyConsented = checkData.success === true || checkData.has_consented === true;
      
      if (alreadyConsented) {
        console.debug('Consent already exists in database, skipping /record');
        return; // No need to send /record if /check is true
      }
    }
    
    // If /check failed or returned false, then send /record
    console.debug('Consent not found in database or check failed, sending /record');
    const recordUrl = `${apiBaseUrl}/api/user/consent/record`;
    
    const recordResponse = await fetch(recordUrl, {
      method: 'POST',
      headers,
      body: JSON.stringify({
        user_id: userId,
        policy_version: PRIVACY_POLICY_VERSION,
        consented_at: new Date().toISOString()
      }),
    });

    if (!recordResponse.ok) {
      console.warn('Failed to record consent remotely, but local fallback is saved');
    }
  } catch (error) {
    console.warn('Error in consent flow:', error);
    // Ignore; local fallback already persisted
  }
}


