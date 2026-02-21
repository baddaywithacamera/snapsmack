/**
 * SnapSmack - Wall Engine JS 
 * Version: 15.8 - Integrated Pro Hotkeys
 * MASTER DIRECTIVE: Full file return. No deletions.
 * - ADDED: Spacebar to move to the next image (next sibling).
 * - ADDED: '1' key to toggle image metadata visibility.
 * - ADDED: Home / End keys for rapid snap-to-start/end navigation.
 * - RETAINED: Infinite scroll, mobile gestures, and physics-driven tilt.
 * - STATUS: Alpha v0.5
 */

const canvas = document.getElementById('wall-canvas');
const zoomLayer = document.getElementById('zoom-layer');
let activeClone = null;
let originTile = null;

// --- CONFIGURATION HANDSHAKE ---
const config = window.WALL_CONFIG || { 
    friction: 0.90, 
    dragWeight: 1.25, 
    pinchPower: 20, 
    totalImages: 0, 
    initialLimit: 100 
};

let friction = config.friction; 
let dragWeight = config.dragWeight; 
let pinchPower = config.pinchPower || 20;
let isDragging = false;
let lastX = 0; 
let lastY = 0;

// PHYSICS & GESTURE STATE
let targetX = 0, currentX = 0;
let targetY = 0, currentY = 0;
let targetZ = -600, currentZ = -600; 
let currentTilt = 0;

// VELOCITY VECTORS
let velocityX = 0;
let velocityY = 0;
let velocityZ = 0; 

// PINCH & ZOOM STATE
let initialPinchDistance = 0;
let initialZ = -600; 
let zoomStartY = 0;
let isZoomDragging = false;
let zoomBaseScale = 1;

// --- INFINITE SCROLL STATE ---
let offset = config.initialLimit || 100; 
let isLoading = false;
let sentinel = null;
let hasMore = (offset < config.totalImages);

// --- INITIALIZATION ---
function initWall() {
    createHelpUI();
    setupInfiniteScroll();
    
    setTimeout(() => {
        const tiles = document.querySelectorAll('.wall-tile');
        if (tiles.length > 0) {
            const midIndex = Math.floor((tiles.length - 1) / 2);
            const midTile = tiles[midIndex];
            const rect = midTile.getBoundingClientRect();
            
            targetX = (window.innerWidth / 2) - (rect.left - currentX + rect.width / 2);
            targetY = (window.innerHeight / 2) - (rect.top - currentY + rect.height / 2);
            
            currentX = targetX;
            currentY = targetY;
        }
    }, 300);
}

function createHelpUI() {
    const toast = document.createElement('div');
    toast.id = 'help-toast';
    toast.innerText = "PRESS H FOR HELP";
    toast.style.cssText = "position: fixed; bottom: 20px; left: 20px; color: var(--wall-text); background: var(--wall-bg); padding: 10px 20px; border: 1px solid var(--wall-text); font-family: 'Courier Prime', monospace; font-size: 12px; z-index: 9999999; pointer-events: none; opacity: 0; transition: opacity 1s; box-shadow: 0 5px 15px rgba(0,0,0,0.5);";
    document.body.appendChild(toast);

    setTimeout(() => toast.style.opacity = '1', 500);
    setTimeout(() => toast.style.opacity = '0', 5000);

    const modal = document.createElement('div');
    modal.id = 'help-modal';
    modal.style.cssText = "display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: var(--wall-bg); border: 2px solid var(--wall-text); padding: 40px; color: var(--wall-text); font-family: 'Courier Prime', monospace; z-index: 10000000; text-align: center; box-shadow: 0 0 50px rgba(0,0,0,0.8); min-width: 300px; border-radius: 4px;";
    modal.innerHTML = `
        <h2 style="color: var(--wall-text); margin-top: 0; text-transform: uppercase; letter-spacing: 2px; border-bottom: 1px solid var(--wall-text); padding-bottom: 15px;">System Controls</h2>
        <ul style="list-style: none; padding: 0; line-height: 2.5; text-align: left; font-size: 0.9rem;">
            <li><strong>ENTER / SPACE</strong> : Zoom Selected</li>
            <li><strong>ARROWS</strong> : Pan Wall / Navigate</li>
            <li><strong>HOME / END</strong> : Jump Start / End</li>
            <li><strong>1</strong> : Toggle Floating Titles</li>
            <li><strong>PG UP / DN</strong> : Zoom Wall In/Out</li>
            <li><strong>H / ESC</strong> : Help / Close</li>
        </ul>
        <div style="margin-top: 20px; font-size: 0.7rem; opacity: 0.7;">SnapSmack Alpha v0.5</div>
    `;
    document.body.appendChild(modal);
}

// --- INFINITE SCROLL LOGIC ---
function setupInfiniteScroll() {
    sentinel = document.getElementById('wall-sentinel');
    const observer = new IntersectionObserver((entries) => {
        if (entries[0].isIntersecting && !isLoading && hasMore && targetZ > -2500) {
            loadMoreTiles();
        }
    }, { rootMargin: '800px' }); 

    if (sentinel) observer.observe(sentinel);
}

async function loadMoreTiles() {
    isLoading = true;
    try {
        const response = await fetch(`load-more.php?offset=${offset}`);
        const html = await response.text();
        
        if (html.trim().length > 0) {
            sentinel.insertAdjacentHTML('beforebegin', html);
            offset += 20; 
            hasMore = (offset < config.totalImages);
        } else {
            hasMore = false; 
        }
    } catch (err) {
        console.error("Pagination fetch failed:", err);
    } finally {
        isLoading = false;
    }
}

function updateCenterFocus() {
    const tiles = document.querySelectorAll('.wall-tile');
    const vwC = window.innerWidth / 2;
    const vhC = window.innerHeight / 2;

    let closestTile = null;
    let minDistance = Infinity;

    tiles.forEach(tile => {
        const rect = tile.getBoundingClientRect();
        const tX = rect.left + (rect.width / 2);
        const tY = rect.top + (rect.height / 2);
        const distance = Math.hypot(vwC - tX, vhC - tY);

        tile.classList.remove('is-centered');

        if (distance < minDistance) {
            minDistance = distance;
            closestTile = tile;
        }
    });

    if (closestTile) {
        closestTile.classList.add('is-centered');
    }
}

function lerp(start, end, factor) { return start + (end - start) * factor; }

function animate() {
    if (!isDragging) {
        targetX += velocityX;
        targetY += velocityY;
        targetZ += velocityZ;

        velocityX *= friction; 
        velocityY *= friction;
        velocityZ *= friction;

        if (Math.abs(velocityX) < 0.05) velocityX = 0;
        if (Math.abs(velocityY) < 0.05) velocityY = 0;
        if (Math.abs(velocityZ) < 0.05) velocityZ = 0;
        
        targetZ = Math.max(-8000, Math.min(800, targetZ));
    }

    currentX = lerp(currentX, targetX, 0.15); 
    currentY = lerp(currentY, targetY, 0.15); 
    currentZ = lerp(currentZ, targetZ, 0.15);
    
    let tiltTarget = velocityX * 0.7; 
    currentTilt = lerp(currentTilt, tiltTarget, 0.1);
    const cappedTilt = Math.max(-18, Math.min(18, currentTilt));

    canvas.style.transform = `translate3d(${currentX}px, ${currentY}px, ${currentZ}px) rotateY(${-cappedTilt}deg)`;
    updateCenterFocus();
    requestAnimationFrame(animate);
}

// --- EVENT LISTENERS ---
window.addEventListener('keydown', (e) => {
    const modal = document.getElementById('help-modal');
    const isModalOpen = (modal && modal.style.display === 'block');
    const isZoomed = zoomLayer.classList.contains('active');

    if (e.key === 'h' || e.key === 'H') {
        e.preventDefault();
        if (modal) modal.style.display = isModalOpen ? 'none' : 'block';
    }
    if (e.key === 'Escape') {
        if (isModalOpen) {
            modal.style.display = 'none';
        } else if (isZoomed) {
            closeZoom();
        } else {
            window.location.href = 'index.php';
        }
    }

    // Toggle Floating Titles
    if (e.key === '1') {
        document.querySelectorAll('.tile-meta').forEach(el => {
            el.style.visibility = (el.style.visibility === 'hidden') ? 'visible' : 'hidden';
        });
    }

    // Next Image Logic (Spacebar)
    if (e.key === ' ') {
        e.preventDefault();
        const sibling = originTile ? originTile.nextElementSibling : document.querySelector('.wall-tile');
        if (sibling && sibling.classList.contains('wall-tile')) {
            if (isZoomed) {
                closeZoom();
                zoomImage(sibling);
            } else {
                // Kick physics left to bring next tile toward center
                velocityX -= 80;
            }
        }
    }

    if (e.key === 'Enter') {
        if (isZoomed) {
            closeZoom();
        } else {
            const centered = document.querySelector('.wall-tile.is-centered');
            if (centered) zoomImage(centered);
        }
    }

    if (isZoomed) {
        if (e.key === 'ArrowLeft') navigateGallery(-1);
        if (e.key === 'ArrowRight') navigateGallery(1);
    } else {
        const pan = 27; const zoom = 45; 
        if (e.key === 'ArrowLeft')  velocityX += pan; 
        if (e.key === 'ArrowRight') velocityX -= pan;
        if (e.key === 'ArrowUp')    velocityY += pan;
        if (e.key === 'ArrowDown')  velocityY -= pan;
        if (e.key === 'PageUp') { e.preventDefault(); velocityZ += zoom; }
        if (e.key === 'PageDown') { e.preventDefault(); velocityZ -= zoom; }
        
        // Rapid Snap Navigation
        if (e.key === 'Home') snapToTile('first');
        if (e.key === 'End') snapToTile('last');
    }
});

function snapToTile(pos) {
    const tiles = document.querySelectorAll('.wall-tile');
    if (tiles.length === 0) return;
    const target = (pos === 'first') ? tiles[0] : tiles[tiles.length - 1];
    const rect = target.getBoundingClientRect();
    targetX = (window.innerWidth / 2) - (rect.left - currentX + rect.width / 2);
    targetY = (window.innerHeight / 2) - (rect.top - currentY + rect.height / 2);
    velocityX = 0; velocityY = 0;
}

window.addEventListener('mousedown', (e) => {
    if(zoomLayer.classList.contains('active')) return;
    isDragging = true; 
    lastX = e.pageX; 
    lastY = e.pageY;
    velocityX = 0; velocityY = 0; velocityZ = 0;
});

window.addEventListener('mousemove', (e) => {
    if (!isDragging) return;
    const dx = (e.pageX - lastX) * dragWeight;
    const dy = (e.pageY - lastY) * dragWeight;
    targetX += dx; targetY += dy;
    velocityX = dx; velocityY = dy;
    lastX = e.pageX; lastY = e.pageY;
});

window.addEventListener('mouseup', () => isDragging = false);

window.addEventListener('wheel', (e) => {
    if (zoomLayer.classList.contains('active')) return;
    velocityZ -= e.deltaY * (pinchPower / 10); 
    e.preventDefault(); 
}, { passive: false });

function getDistance(t) { return Math.hypot(t[0].pageX - t[1].pageX, t[0].pageY - t[1].pageY); }

window.addEventListener('touchstart', (e) => {
    if(zoomLayer.classList.contains('active')) return;
    if (e.touches.length === 2) {
        initialPinchDistance = getDistance(e.touches);
        initialZ = targetZ;
        velocityZ = 0; 
    } else {
        isDragging = true;
        lastX = e.touches[0].pageX; lastY = e.touches[0].pageY;
        velocityX = 0; velocityY = 0; velocityZ = 0;
    }
}, { passive: false });

window.addEventListener('touchmove', (e) => {
    if (e.touches.length === 2) {
        const d = getDistance(e.touches) - initialPinchDistance;
        targetZ = Math.max(-8000, Math.min(800, initialZ + (d * pinchPower))); 
    } else if (isDragging) {
        const dx = (e.touches[0].pageX - lastX) * dragWeight;
        const dy = (e.touches[0].pageY - lastY) * dragWeight;
        targetX += dx; targetY += dy;
        velocityX = dx; velocityY = dy;
        lastX = e.touches[0].pageX; lastY = e.touches[0].pageY;
        if (Math.abs(velocityX) > 5) e.preventDefault(); 
    }
}, { passive: false });

window.addEventListener('touchend', () => isDragging = false);

// --- ZOOM & GALLERY LOGIC ---
function navigateGallery(direction) {
    if (!originTile) return;
    const sibling = direction === 1 ? originTile.nextElementSibling : originTile.previousElementSibling;
    if (sibling && sibling.classList.contains('wall-tile')) {
        if(activeClone && activeClone.parentNode) {
            document.body.removeChild(activeClone);
            activeClone = null;
        }
        zoomLayer.classList.remove('active'); 
        zoomImage(sibling);
    }
}

function zoomImage(el) {
    originTile = el;
    const img = el.querySelector('img');
    const rect = img.getBoundingClientRect();
    const fullRes = el.getAttribute('data-full');

    activeClone = document.createElement('img');
    activeClone.className = "zoom-clone";
    activeClone.src = img.src;
    activeClone.style.cssText = `width:${rect.width}px; height:${rect.height}px; left:${rect.left}px; top:${rect.top}px;`;
    
    document.body.appendChild(activeClone);
    zoomLayer.classList.add('active');
    
    requestAnimationFrame(() => {
        const pad = window.innerWidth > 768 ? 0.95 : 1.0;
        zoomBaseScale = Math.min((window.innerWidth * pad) / rect.width, (window.innerHeight * pad) / rect.height);
        const cX = (window.innerWidth / 2) - rect.left - (rect.width / 2);
        const cY = (window.innerHeight / 2) - rect.top - (rect.height / 2);
        activeClone.style.transform = `translate(${cX}px, ${cY}px) scale(${zoomBaseScale})`;
        
        const high = new Image();
        high.src = fullRes;
        high.onload = () => { if(activeClone) activeClone.src = fullRes; };
    });

    activeClone.addEventListener('touchstart', (e) => {
        zoomStartY = e.touches[0].pageY;
        isZoomDragging = true;
        activeClone.classList.add('dragging');
    });

    activeClone.addEventListener('touchmove', (e) => {
        if (!isZoomDragging) return;
        const deltaY = e.touches[0].pageY - zoomStartY;
        if (deltaY > 0) {
            const cX = (window.innerWidth / 2) - rect.left - (rect.width / 2);
            const cY = (window.innerHeight / 2) - rect.top - (rect.height / 2);
            const dragScale = Math.max(0.6, zoomBaseScale * (1 - deltaY / 1200));
            activeClone.style.transform = `translate(${cX}px, ${cY + deltaY}px) scale(${dragScale})`;
            zoomLayer.style.opacity = 1 - (deltaY / 600);
        }
    });

    activeClone.addEventListener('touchend', (e) => {
        isZoomDragging = false;
        activeClone.classList.remove('dragging');
        if ((e.changedTouches[0].pageY - zoomStartY) > 150) {
            closeZoom();
        } else {
            const cX = (window.innerWidth / 2) - rect.left - (rect.width / 2);
            const cY = (window.innerHeight / 2) - rect.top - (rect.height / 2);
            activeClone.style.transform = `translate(${cX}px, ${cY}px) scale(${zoomBaseScale})`;
            zoomLayer.style.opacity = 1;
        }
    });

    activeClone.onclick = (e) => { if(!isZoomDragging) closeZoom(); };
}

function closeZoom() {
    zoomLayer.classList.remove('active');
    if (!activeClone) return;
    activeClone.style.transform = `translate(0, 0) scale(1)`;
    activeClone.style.opacity = "0";
    setTimeout(() => {
        if (activeClone && activeClone.parentNode) {
            document.body.removeChild(activeClone);
            activeClone = null;
        }
    }, 500);
}

zoomLayer.onclick = closeZoom;
window.addEventListener('load', initWall);
animate();