import { useEffect } from 'react';

declare global {
  interface Window {
    fbq: any;
  }
}

export const MetaPixel = () => {
  useEffect(() => {
    // Only load tracking on production domain
    if (window.location.hostname !== 'viewsmax.com') {
      return;
    }

    // Load Facebook Pixel script
    const script = document.createElement('script');
    script.innerHTML = `
      !function(f,b,e,v,n,t,s)
      {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
      n.callMethod.apply(n,arguments):n.queue.push(arguments)};
      if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
      n.queue=[];t=b.createElement(e);t.async=!0;
      t.src=v;s=b.getElementsByTagName(e)[0];
      s.parentNode.insertBefore(t,s)}(window, document,'script',
      'https://connect.facebook.net/en_US/fbevents.js');
      fbq('init', '1380310456956732');
      fbq('track', 'PageView');
    `;
    document.head.appendChild(script);

    // Add noscript fallback
    const noscript = document.createElement('noscript');
    const img = document.createElement('img');
    img.height = '1';
    img.width = '1';
    img.style.display = 'none';
    img.src = 'https://www.facebook.com/tr?id=1380310456956732&ev=PageView&noscript=1';
    noscript.appendChild(img);
    document.body.appendChild(noscript);

    return () => {
      // Cleanup on unmount
      if (script.parentNode) {
        script.parentNode.removeChild(script);
      }
      if (noscript.parentNode) {
        noscript.parentNode.removeChild(noscript);
      }
    };
  }, []);

  return null;
};

// Utility functions for tracking events
export const trackEvent = (eventName: string, parameters?: any) => {
  if (typeof window !== 'undefined' && window.location.hostname === 'viewsmax.com' && window.fbq) {
    window.fbq('track', eventName, parameters);
  }
};

export const trackCustomEvent = (eventName: string, parameters?: any) => {
  if (typeof window !== 'undefined' && window.location.hostname === 'viewsmax.com' && window.fbq) {
    window.fbq('trackCustom', eventName, parameters);
  }
};
