const CACHE_NAME = 'amezprice-notifications-v1';
const IMAGE_CACHE = 'amezprice-images-v1';
const NOTIFICATION_TAG = 'amezprice-deal';

// Initialize IndexedDB for background sync
let db;
const dbPromise = indexedDB.open('amezprice-sync', 1);

dbPromise.onupgradeneeded = (event) => {
    db = event.target.result;
    db.createObjectStore('sync-actions', { keyPath: 'id', autoIncrement: true });
};

dbPromise.onsuccess = (event) => {
    db = event.target.result;
};

// Cache notification images including bell icon
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(IMAGE_CACHE).then((cache) => {
            return cache.addAll([
                '/assets/images/logos/website-logo.png',
                '/assets/images/logos/amazon.svg',
                '/assets/images/logos/flipkart.svg',
                '/assets/images/icons/bell.png' // Added bell icon
            ]);
        })
    );
});

// Clean up old caches
self.addEventListener('activate', (event) => {
    const cacheWhitelist = [CACHE_NAME, IMAGE_CACHE];
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cacheName) => {
                    if (!cacheWhitelist.includes(cacheName)) {
                        return caches.delete(cacheName);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

// Handle push events
self.addEventListener('push', (event) => {
    if (!event.data) return;

    let payload;
    try {
        payload = event.data.json();
    } catch (e) {
        console.error('Invalid push payload:', e);
        fileLog('Invalid push payload: ' + e.message);
        return;
    }

    const { title, message, previous_price, current_price, tracker_count, image_path, affiliate_link, history_url, category, urgency, timestamp, product_asin } = payload;

    // Filter notifications based on user preferences
    const preferences = getUserPreferences();
    if (preferences.categories && !preferences.categories.includes(category)) {
        return;
    }

    const options = {
        body: `${message}\n\nPrevious: ₹${Number(previous_price).toLocaleString('en-IN')} | Current: ₹${Number(current_price).toLocaleString('en-IN')}\n\n🔥 ${tracker_count} users tracking`,
        icon: image_path || '/assets/images/logos/website-logo.png',
        badge: '/assets/images/icons/bell.png', // Changed to bell icon
        image: image_path,
        tag: NOTIFICATION_TAG + '-' + category,
        renotify: true,
        vibrate: urgency === 'high' ? [200, 100, 200, 100, 200] : [100, 50, 100],
        requireInteraction: true,
        timestamp: timestamp || Date.now(),
        data: {
            buy_now_url: affiliate_link,
            history_url: history_url,
            category: category,
            product_asin: product_asin
        },
        actions: [
            { action: 'buy_now', title: 'Buy Now' },
            { action: 'price_history', title: 'Price History' },
            { action: 'track', title: 'Track Product' },
            { action: 'share', title: 'Share Deal' }
        ]
    };

    event.waitUntil(
        Promise.all([
            self.registration.showNotification(title, options),
            trackInteraction('notification_received', product_asin)
        ]).catch((error) => {
            console.error('Notification error:', error);
            fileLog('Notification display failed: ' + error.message);
        })
    );
});

// Handle notification clicks
self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const { action, data } = event.notification;

    let interactionType;
    switch (action) {
        case 'buy_now':
            interactionType = 'notification_buy_now';
            break;
        case 'price_history':
            interactionType = 'notification_price_history';
            break;
        case 'track':
        case 'share':
            interactionType = 'notification_' + action;
            break;
        default:
            interactionType = 'notification_clicked';
    }

    event.waitUntil(
        handleAction(action, data).then(() => {
            return trackInteraction(interactionType, data.product_asin);
        }).catch((error) => {
            console.error('Action error:', error);
            fileLog('Action failed: ' + error.message);
        })
    );
});

// Handle notification close (dismiss)
self.addEventListener('notificationclose', (event) => {
    const { data } = event.notification;
    event.waitUntil(
        trackInteraction('notification_dismissed', data.product_asin).catch((error) => {
            console.error('Dismiss tracking error:', error);
            fileLog('Dismiss tracking failed: ' + error.message);
        })
    );
});

// Handle background sync
self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-actions') {
        event.waitUntil(syncActions());
    }
});

// Helper Functions
async function handleAction(action, data) {
    if (!navigator.onLine) {
        await queueAction(action, data);
        return;
    }

    switch (action) {
        case 'buy_now':
            await clients.openWindow(data.buy_now_url);
            break;
        case 'price_history':
            await clients.openWindow(data.history_url);
            break;
        case 'track':
            await fetch('/user/toggle_favorite.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': await getCsrfToken() },
                body: JSON.stringify({ product_asin: data.product_asin, is_favorite: true })
            });
            break;
        case 'share':
            await clients.openWindow(`https://t.me/share/url?url=${encodeURIComponent(data.buy_now_url)}&text=${encodeURIComponent('Check out this deal on AmezPrice!')}`);
            break;
        default:
            await clients.openWindow('/');
    }
}

async function queueAction(action, data) {
    const transaction = db.transaction(['sync-actions'], 'readwrite');
    const store = transaction.objectStore('sync-actions');
    await store.add({ action, data, timestamp: Date.now() });
}

async function syncActions() {
    if (!navigator.onLine) return;

    const transaction = db.transaction(['sync-actions'], 'readwrite');
    const store = transaction.objectStore('sync-actions');
    const actions = await store.getAll();

    for (const action of actions) {
        await handleAction(action.action, action.data);
        await store.delete(action.id);
    }
}

async function trackInteraction(type, productAsin) {
    if (!navigator.onLine) return;

    await fetch('/user/track_interaction.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': await getCsrfToken() },
        body: JSON.stringify({ type, product_asin: productAsin })
    });
}

async function getCsrfToken() {
    const response = await fetch('/middleware/csrf.php', { method: 'GET' });
    const text = await response.text();
    const match = text.match(/content='([^']+)'/);
    return match ? match[1] : '';
}

function getUserPreferences() {
    return { categories: ['smartphone', 'television'] }; // Example
}

function fileLog(message) {
    fetch('/push_notification/log_error.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message, timestamp: Date.now() })
    });
}