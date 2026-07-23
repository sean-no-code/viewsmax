import { useEffect } from 'react';
import { useNavigate } from 'react-router-dom';

const OAuthCallback = () => {
  const navigate = useNavigate();

  useEffect(() => {
    const handleOAuthCallback = () => {
      const urlParams = new URLSearchParams(window.location.search);
      const code = urlParams.get('code');
      const error = urlParams.get('error');
      const state = urlParams.get('state');

      // The provider is encoded as the prefix of `state` (e.g. "tiktok_169...").
      // Defaults to youtube for back-compat with the legacy flow.
      const provider = (state?.split('_')[0] || 'youtube');

      console.log('OAuth callback received:', { code: !!code, error, state, provider });

      if (error) {
        console.error('OAuth error:', error);

        // Send error to parent window if opened as popup
        if (window.opener) {
          // Generic message for the multi-provider flow...
          window.opener.postMessage({
            type: 'OAUTH_ERROR',
            provider,
            state,
            error: error
          }, window.location.origin);
          // ...plus the legacy YouTube message so youtube-auth.ts still resolves.
          if (provider === 'youtube') {
            window.opener.postMessage({
              type: 'YOUTUBE_OAUTH_ERROR',
              error: error
            }, window.location.origin);
          }
          window.close();
          return;
        }

        // If not popup, redirect to analytics with error
        navigate('/dashboard/analytics?error=' + encodeURIComponent(error));
        return;
      }

      if (code) {
        console.log('✅ OAuth authorization code received');

        // Send code to parent window if opened as popup
        if (window.opener) {
          // Generic message for the multi-provider flow...
          window.opener.postMessage({
            type: 'OAUTH_SUCCESS',
            provider,
            state,
            code: code
          }, window.location.origin);
          // ...plus the legacy YouTube message for back-compat.
          if (provider === 'youtube') {
            window.opener.postMessage({
              type: 'YOUTUBE_OAUTH_SUCCESS',
              code: code
            }, window.location.origin);
          }
          window.close();
          return;
        }
        
        // If not popup, handle the code here and redirect
        // For now, we'll store the code and redirect
        localStorage.setItem('youtube_oauth_code', code);
        navigate('/dashboard/analytics?code=' + encodeURIComponent(code));
        return;
      }

      // If no code or error, redirect to analytics
      console.log('No code or error, redirecting to analytics');
      navigate('/dashboard/analytics');
    };

    handleOAuthCallback();
  }, [navigate]);

  return (
    <div className="flex items-center justify-center min-h-screen bg-gradient-secondary">
      <div className="text-center p-8">
        <div className="animate-spin rounded-full h-16 w-16 border-b-4 border-primary mx-auto mb-6"></div>
        <h2 className="text-xl font-semibold mb-2">Completing YouTube Connection</h2>
        <p className="text-muted-foreground mb-4">Finalizing your channel authentication...</p>
        <div className="text-sm text-muted-foreground">
          This should only take a moment
        </div>
      </div>
    </div>
  );
};

export default OAuthCallback;
