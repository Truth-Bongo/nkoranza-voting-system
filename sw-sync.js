// sw-sync.js
const CACHE_NAME = 'vote-sync-v1';
const SYNC_TAG = 'vote-sync';

// Install event
self.addEventListener('install', (event) => {
    console.log('Service Worker installed');
    self.skipWaiting();
});

// Activate event
self.addEventListener('activate', (event) => {
    console.log('Service Worker activated');
    event.waitUntil(self.clients.claim());
});

// Background sync event
self.addEventListener('sync', (event) => {
    if (event.tag === SYNC_TAG) {
        console.log('Background sync triggered');
        event.waitUntil(syncPendingVotes());
    }
});

// Periodic sync (for newer browsers)
self.addEventListener('periodicsync', (event) => {
    if (event.tag === 'vote-periodic-sync') {
        console.log('Periodic sync triggered');
        event.waitUntil(syncPendingVotes());
    }
});

// Sync pending votes function
async function syncPendingVotes() {
    try {
        const pendingVotes = await getPendingVotes();
        
        if (pendingVotes.length === 0) {
            console.log('No pending votes to sync');
            return;
        }

        const response = await fetch(`${self.origin}/api/voting/sync-pending-votes.php`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ pending_votes: pendingVotes })
        });

        if (response.ok) {
            const result = await response.json();
            
            if (result.success) {
                console.log('Background sync successful:', result.processed, 'votes synced');
                
                // Remove successfully synced votes
                await removeSyncedVotes(result.results.successful);
                
                // Notify all clients about the sync result
                const clients = await self.clients.matchAll();
                clients.forEach(client => {
                    client.postMessage({
                        type: 'SYNC_COMPLETED',
                        success: true,
                        processed: result.processed
                    });
                });
            }
        }
    } catch (error) {
        console.error('Background sync failed:', error);
        
        // Notify clients about sync failure
        const clients = await self.clients.matchAll();
        clients.forEach(client => {
            client.postMessage({
                type: 'SYNC_FAILED',
                error: error.message
            });
        });
    }
}

// Get pending votes from IndexedDB
async function getPendingVotes() {
    return new Promise((resolve) => {
        const request = indexedDB.open('VoteAppDB', 1);
        
        request.onsuccess = (event) => {
            const db = event.target.result;
            const transaction = db.transaction(['pendingVotes'], 'readonly');
            const store = transaction.objectStore('pendingVotes');
            const getAllRequest = store.getAll();
            
            getAllRequest.onsuccess = () => {
                resolve(getAllRequest.result || []);
            };
            
            getAllRequest.onerror = () => {
                console.error('Error reading pending votes from IndexedDB');
                resolve([]);
            };
        };
        
        request.onerror = () => {
            console.error('Error opening IndexedDB');
            resolve([]);
        };
    });
}

// Remove synced votes from IndexedDB
async function removeSyncedVotes(syncedVotes) {
    if (!syncedVotes || syncedVotes.length === 0) return;

    return new Promise((resolve) => {
        const request = indexedDB.open('VoteAppDB', 1);
        
        request.onsuccess = (event) => {
            const db = event.target.result;
            const transaction = db.transaction(['pendingVotes'], 'readwrite');
            const store = transaction.objectStore('pendingVotes');
            
            syncedVotes.forEach(vote => {
                const key = `${vote.election_id}_${vote.timestamp}`;
                store.delete(key);
            });
            
            transaction.oncomplete = () => {
                console.log('Removed synced votes from storage');
                resolve();
            };
            
            transaction.onerror = () => {
                console.error('Error removing synced votes');
                resolve();
            };
        };
        
        request.onerror = () => {
            console.error('Error opening IndexedDB for cleanup');
            resolve();
        };
    });
}