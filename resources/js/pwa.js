if (import.meta.env.PROD && !import.meta.hot && window.isSecureContext && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(error => {
            console.warn('MapR service worker registration failed.', error);
        });
    }, { once: true });
}
