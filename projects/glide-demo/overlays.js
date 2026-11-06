(() => {
    const photos = [...new Map([...document.querySelectorAll('.glide-tile')].map(link => [link.href, link])).values()]
        .map(link => {
            const image = link.querySelector('img');
            return { url: link.href, title: link.getAttribute('aria-label'), src: image.src, alt: image.alt };
        });
    document.body.insertAdjacentHTML('beforeend', `
        <details class="portal-title-settings">
            <summary>Preview title settings</summary>
            <label><input type="checkbox" checked> Show photo title</label>
            <label>Font <select><option value="system-ui">System Sans</option><option value="Georgia">Georgia</option><option value="monospace">Monospace</option></select></label>
            <label>Size <input type="number" min="10" max="36" value="16"> px</label>
        </details>
        <form class="portal-search" role="search" aria-label="Search the Portal Project">
            <input type="search" aria-label="Search photos" placeholder="Search photos" hidden>
            <button type="button" aria-label="Search photos" aria-expanded="false"><svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2"><circle cx="10" cy="10" r="6"/><path d="m15 15 5 5"/></svg></button>
        </form>
        <dialog id="portal-archive" class="portal-panel" aria-labelledby="archive-title">
            <header><h1 id="archive-title" tabindex="-1">Archive</h1><form method="dialog"><button aria-label="Close archive">✕</button></form></header>
            <div class="portal-grid"></div><p class="portal-empty" hidden>No matching photos.</p>
            <nav class="portal-pagination" aria-label="Archive pages"><button type="button">Previous page</button><span role="status"></span><button type="button">Next page</button></nav>
        </dialog>
        <dialog id="portal-blogroll" class="portal-panel portal-blogroll" aria-labelledby="blogroll-title">
            <header><h1 id="blogroll-title">Blogroll</h1><form method="dialog"><button aria-label="Close blogroll">✕</button></form></header>
            <section><h2>FRIENDS AND FAMILY</h2><ul><li><a href="https://awaywithacamera.com/" target="_blank" rel="noopener noreferrer">Away With A Camera</a><p>Some of the best travel photography you&#x27;ll ever see because much of it is not exotic, but real images from local daytrips done in McGinnis style.</p></li><li><a href="https://rickmcginnisphotographs.wordpress.com/" target="_blank" rel="noopener noreferrer">Rick McGinnis Photographs</a><p>Rick is a photographer I have known for a long time and who never stops making me want to be better at what I do.</p></li></ul></section>
            <section><h2>INSPIRATION</h2><ul><li><a href="https://photoblog.dview.us/" target="_blank" rel="noopener noreferrer">A Different View</a><p>This is the photoblog of Jay Williams who was one of the devs part of rebuilding Pixelpost. The project wasn&#x27;t able to be finished, but he&#x27;s a free software pioneer, a damn fine photographer, and to me is a legend of the photo blogging community. All the props to you, sir.</p></li><li><a href="https://www.allencompassingtrip.com/" target="_blank" rel="noopener noreferrer">All Encompassing Trip</a><p>Guillermo Esteves is a U.S. based photographer who travels and does justice to every location with lucious photography. His site is also lucious, one big beautiful scroll. You should go look. Now.</p></li><li><a href="https://lifesquared.squarespace.com/blog" target="_blank" rel="noopener noreferrer">Life Squared</a><p>Mark Hobson is a cranky old bugger who takes pictures of things I would not ordinarily think of taking pictures of and they all work, damn it. If you want to train your eye he&#x27;s a good person to follow.</p></li></ul></section>
            <section><h2>MY BLOGS</h2><ul><li><a href="https://acolourlesslife.ca/" target="_blank" rel="noopener noreferrer">a colourless life</a><p>autistic black and white thinking in action</p></li><li><a href="https://allinthewrist.photoblogs.fyi/" target="_blank" rel="noopener noreferrer">all in the wrist</a><p>let&#x27;s get bougee</p></li><li><a href="https://baddaywithacamera.ca/" target="_blank" rel="noopener noreferrer">baddaywithacamera.ca</a><p>beats a good day doing anything else</p></li><li><a href="https://craptasti.ca/" target="_blank" rel="noopener noreferrer">craptasti.ca</a><p>where pixel dump has a new meaning</p></li><li><a href="https://curbappeal.photoblogs.fyi/" target="_blank" rel="noopener noreferrer">curb appeal</a><p>when life kicks you to the curb, take a photo</p></li><li><a href="https://foreverphotograph.ing/" target="_blank" rel="noopener noreferrer">forever photographing</a><p>it&#x27;s not a hobby, it&#x27;s my life</p></li><li><a href="https://foundtextures.ca/" target="_blank" rel="noopener noreferrer">found textures</a><p>embracing entropy</p></li><li><a href="https://hekeepsdroningon.ca/" target="_blank" rel="noopener noreferrer">he keeps droning on</a><p>the sky&#x27;s the limit</p></li><li><a href="https://fauxlaroid.fyi/" target="_blank" rel="noopener noreferrer">it&#x27;s a fauxlaroid, fyi</a><p>make it &#x27;til you fake it</p></li><li><a href="https://lightafterdark.ca/" target="_blank" rel="noopener noreferrer">light after dark</a><p>i&#x27;m the bump you hear in the night</p></li><li><a href="https://mccormickphotography.com/" target="_blank" rel="noopener noreferrer">mccormick photography</a><p>Saving the beauty in this moment for the ones that follow.</p></li><li><a href="https://photowalk.ing/" target="_blank" rel="noopener noreferrer">photowalk.ing</a><p>every day is a new journey and a new image</p></li><li><a href="https://pixhellated.ca/" target="_blank" rel="noopener noreferrer">pixhellated</a><p>life in pictures, er, pixels.</p></li><li><a href="https://squaredstraight.ca/" target="_blank" rel="noopener noreferrer">squared straight</a><p>be square or be there</p></li><li><a href="https://theschoolofhardnocks.ca/" target="_blank" rel="noopener noreferrer">the school of hard nocks</a><p>it&#x27;s a hard nock life</p></li><li><a href="https://unzucked.ca/" target="_blank" rel="noopener noreferrer">unzucked</a><p>zuck fkkkdd no more</p></li><li><a href="https://usedcarparts.photoblogs.fyi/" target="_blank" rel="noopener noreferrer">used car parts</a><p>maroon car my ass, this motherfucker&#x27;s red</p></li><li><a href="https://wateronthebrain.ca/" target="_blank" rel="noopener noreferrer">water on the brain</a><p>learning to go with the flow</p></li></ul></section>
            <section><h2>WHAT BLOGGING SHOULD BE</h2><ul><li><a href="https://chrisglass.com/photos/" target="_blank" rel="noopener noreferrer">Chris Glass / Daily Photo Journal</a><p>Good pictures, bad, pictures, lots of pictures. The important part is there are DAILY pictures with some good writing thrown in.</p></li><li><a href="https://daytrippen.ca/" target="_blank" rel="noopener noreferrer">Daytrippen Travel Adventures</a><p>A right proper travel blog with relevant photos. Better yet, it&#x27;s Canadian (take that, Yanks!). Worth a visit, especially for those interested in traveling the Canadian province of Ontario.</p></li><li><a href="https://blog.jimgrey.net/" target="_blank" rel="noopener noreferrer">Down the Road</a><p>Photos and words from Jim Grey. I don&#x27;t agree with all his ideas but I like that he makes arguments worth listening to and that should not be dismissed out of hand. The travel photography is real and on point, served with a side of good writing.</p></li><li><a href="https://olaaf.eu/" target="_blank" rel="noopener noreferrer">OLAAF</a><p>Belgian blogger, food, timepieces, travel, and more. Good stories, great photos. A real person sharing real things.</p></li></ul></section>
        </dialog>
        <dialog id="portal-about" class="portal-panel portal-about" aria-labelledby="about-title">
            <header><h1 id="about-title">About the Portal Project</h1><form method="dialog"><button aria-label="Close about">✕</button></form></header>
            <div class="portal-about-content"><button type="button" class="portal-about-portrait" aria-label="Enlarge Sean McCormick portrait"><img width="2000" height="3000" src="SeanDoorwayProfile.jpg" alt="Sean McCormick in a Kodachrome shirt with his hand on a weathered doorframe, prairie behind him"></button><div><h2>Sean McCormick</h2>
            <p>Sean McCormick is a Canadian photographer living just east of Calgary, Alberta. His work ranges from prairie landscapes to the everyday details of streets and buildings, shot on everything from toy cameras and vintage film bodies to high-end digital. He shares it across a collection of photoblogs, all running on SnapSmack, the AI-built software behind the Portal Project.</p>
            <p>Here, he photographs doors and entrances. A weathered back door, a storefront, or the entrance to a public building can tell you something about the people who use it and the place it belongs to.</p>
            <p><a class="portal-about-link" href="https://linktr.ee/mccormickphotography" target="_blank" rel="noopener">More of Sean&#8217;s work</a></p></div></div>
        </dialog>
        <dialog class="portal-photo" aria-label="Photograph viewer" tabindex="-1" autofocus>
            <figure>
                <img alt=""><figcaption id="photo-title"></figcaption>
                <button type="button" aria-label="Previous photograph"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m15 4-8 8 8 8"/></svg></button>
                <button type="button" aria-label="Next photograph"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 4 8 8-8 8"/></svg></button>
                <div class="portal-photo-tools">
                    <button type="button" data-pane="info" aria-label="Post info" aria-pressed="false">ⓘ</button>
                    <button type="button" data-pane="comments" aria-label="Comments" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11a7 7 0 0 1-7 7H8l-4 3v-5a7 7 0 0 1 7-12h2a7 7 0 0 1 7 7Z"/></svg></button>
                </div>
                <section class="portal-photo-pane" aria-labelledby="portal-pane-title" inert>
                    <h2 id="portal-pane-title"></h2><p></p>
                </section>
            </figure>
        </dialog>`);
    const archive = document.querySelector('#portal-archive');
    const blogroll = document.querySelector('#portal-blogroll');
    const about = document.querySelector('#portal-about');
    document.querySelector('.glide-nav .ss-grid-nav-links').insertAdjacentHTML('beforeend',
        '<a class="ss-grid-nav-link" href="#about" title="About"><svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v2"/></svg><span class="ss-grid-nav-label">About</span></a>');
    const viewer = document.querySelector('.portal-photo');
    const grid = archive.querySelector('.portal-grid');
    const pagination = archive.querySelector('.portal-pagination');
    const [previousPage, nextPage] = pagination.querySelectorAll('button');
    const pageStatus = pagination.querySelector('[role=status]');
    const pageSize = 50;
    const searchForm = document.querySelector('.portal-search');
    const search = searchForm.querySelector('input');
    const searchButton = searchForm.querySelector('button');
    const image = viewer.querySelector('img');
    const standardShortcuts = window.snapKeyboard.shortcuts;
    window.snapKeyboard.shortcuts = () => viewer.open
        ? [['LEFT / SPACE', 'Previous photo'], ['RIGHT', 'Next photo'], ['1', 'Toggle post info'], ['2', 'Toggle comments'], ['ESC', 'Close panel or viewer'], ...standardShortcuts()]
        : standardShortcuts();
    const settings = document.querySelector('.portal-title-settings');
    settings.addEventListener('input', () => {
        viewer.querySelector('figcaption').hidden = !settings.querySelector('[type=checkbox]').checked;
        viewer.style.setProperty('--portal-title-font', settings.querySelector('select').value);
        const size = settings.querySelector('[type=number]').valueAsNumber;
        if (Number.isFinite(size)) viewer.style.setProperty('--portal-title-size', Math.min(36, Math.max(10, size)) + 'px');
    });
    const reduced = matchMedia('(prefers-reduced-motion: reduce)');
    let matches = photos;
    let pageIndex = 0;
    let active = 0;
    let navigation = 0;

    function renderPage() {
        grid.replaceChildren();
        const start = pageIndex * pageSize;
        const batch = matches.slice(start, start + pageSize);
        for (const [offset, photo] of batch.entries()) {
            const tile = document.createElement('button');
            tile.type = 'button';
            tile.className = 'portal-tile';
            tile.style.setProperty('--delay', `${Math.floor(offset / 5) * 125 + (offset % 5) * 25}ms`);
            const thumb = new Image();
            thumb.loading = offset < 15 ? 'eager' : 'lazy';
            thumb.src = photo.src;
            thumb.alt = photo.alt;
            const label = document.createElement('span');
            label.textContent = photo.title;
            tile.append(thumb, label);
            const index = start + offset;
            tile.addEventListener('click', () => {
                viewer.showModal();
                showPhoto(index, 1, true);
            });
            grid.append(tile);
        }
        const firstTile = grid.firstElementChild;
        if (firstTile && !reduced.matches) {
            grid.classList.add('portal-awaiting-photos');
            const leadingPhotos = Array.from(grid.querySelectorAll('img')).slice(0, 15);
            Promise.race([
                Promise.allSettled(leadingPhotos.map(photo => photo.decode())),
                new Promise(resolve => setTimeout(resolve, 500)),
            ]).then(() => {
                if (grid.firstElementChild === firstTile) grid.classList.remove('portal-awaiting-photos');
            });
        } else {
            grid.classList.remove('portal-awaiting-photos');
        }
        const pages = Math.ceil(matches.length / pageSize);
        pagination.hidden = pages <= 1;
        previousPage.disabled = pageIndex === 0;
        nextPage.disabled = pageIndex >= pages - 1;
        pageStatus.textContent = pages ? 'Page ' + (pageIndex + 1) + ' of ' + pages : '';
        archive.scrollTop = 0;
    }

    function changePage(direction) {
        const next = pageIndex + direction;
        if (next < 0 || next >= Math.ceil(matches.length / pageSize)) return;
        pageIndex = next;
        renderPage();
        archive.querySelector('h1').focus({ preventScroll: true });
    }

    previousPage.addEventListener('click', () => changePage(-1));
    nextPage.addEventListener('click', () => changePage(1));
    function filter() {
        const query = search.value.trim().toLowerCase();
        matches = photos.filter(photo => `${photo.title} ${photo.alt}`.toLowerCase().includes(query));
        pageIndex = 0;
        archive.querySelector('.portal-empty').hidden = matches.length > 0;
        renderPage();
    }

    async function toggleSearch(open) {
        searchButton.setAttribute('aria-expanded', String(open));
        if (open) {
            search.classList.remove('is-closing');
            search.hidden = false;
            search.inert = false;
            search.focus();
            return;
        }
        searchButton.focus();
        search.inert = true;
        search.classList.add('is-closing');
        const closing = search.getAnimations().find(animation => animation.animationName === 'search-out');
        if (closing) {
            await closing.finished.catch(() => {});
            if (closing.playState !== 'finished') return;
        }
        if (searchButton.getAttribute('aria-expanded') === 'true') return;
        search.hidden = true;
        search.classList.remove('is-closing');
    }

    searchButton.addEventListener('click', () => toggleSearch(searchButton.getAttribute('aria-expanded') !== 'true'));
    searchForm.addEventListener('submit', event => {
        event.preventDefault();
        if (!search.value.trim()) return;
        history.replaceState(null, '', '#archive');
        openPanel(archive);
    });
    search.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            toggleSearch(false);
        }
    });

    const photoPane = viewer.querySelector('.portal-photo-pane');
    const paneButtons = [...viewer.querySelectorAll('[data-pane]')];
    let displayedPhoto;
    let activePane = '';

    function updatePhotoPane() {
        if (!displayedPhoto || !activePane) return;
        const info = activePane === 'info';
        photoPane.querySelector('h2').textContent = info ? displayedPhoto.title : 'Comments';
        photoPane.querySelector('p').textContent = info ? displayedPhoto.alt : 'Be the first to comment.';
    }

    function setPhotoPane(name) {
        if (!name && photoPane.contains(document.activeElement)) {
            paneButtons.find(button => button.dataset.pane === activePane)?.focus();
        }
        activePane = name;
        viewer.classList.toggle('has-photo-pane', Boolean(name));
        photoPane.inert = !name;
        for (const button of paneButtons) button.setAttribute('aria-pressed', String(button.dataset.pane === name));
        updatePhotoPane();
    }

    for (const button of paneButtons) {
        button.addEventListener('click', () => setPhotoPane(activePane === button.dataset.pane ? '' : button.dataset.pane));
    }

    async function showPhoto(index, direction, opening = false) {
        if (closingViewer) return;
        const request = ++navigation;
        active = (index + matches.length) % matches.length;
        const photo = matches[active];
        const preload = new Image();
        preload.src = photo.src;
        try { await preload.decode(); } catch { return; }
        if (request !== navigation || !viewer.open) return;
        const transform = angle => `perspective(1200px) rotateY(${angle}deg)`;
        if (!opening && !reduced.matches) {
            const outgoing = image.animate([{ transform: transform(0) }, { transform: transform(-90 * direction) }], { duration: 160, fill: 'forwards' });
            await outgoing.finished.catch(() => {});
            outgoing.cancel();
        }
        if (request !== navigation || !viewer.open) return;
        displayedPhoto = photo;
        updatePhotoPane();
        image.src = photo.src;
        image.alt = photo.alt;
        viewer.querySelector('figcaption').textContent = photo.title;
        if (!reduced.matches) image.animate([{ transform: transform(90 * direction) }, { transform: transform(0) }], { duration: opening ? 320 : 160 });
    }

    viewer.querySelector('[aria-label="Previous photograph"]').onclick = () => showPhoto(active - 1, -1);
    viewer.querySelector('[aria-label="Next photograph"]').onclick = () => showPhoto(active + 1, 1);
    let closingViewer = false;
    async function closeViewer() {
        if (!viewer.open || closingViewer) return;
        closingViewer = true;
        navigation++;
        try {
            if (!reduced.matches && image.hasAttribute('src')) {
                image.getAnimations().forEach(animation => animation.cancel());
                const flip = image.animate([
                    { transform: 'perspective(1200px) rotateY(0deg)' },
                    { transform: 'perspective(1200px) rotateY(-90deg)' }
                ], { duration: 240, easing: 'ease-in', fill: 'forwards' });
                await flip.finished.catch(() => {});
                viewer.classList.add('viewer-fading');
                const fade = viewer.animate([{ opacity: 1 }, { opacity: 0 }],
                    { duration: 180, easing: 'ease-out', fill: 'forwards' });
                const backdrop = viewer.animate([
                    { backgroundColor: 'rgb(0 0 0 / 80%)' },
                    { backgroundColor: 'transparent' }
                ], { pseudoElement: '::backdrop', duration: 180, easing: 'ease-out', fill: 'forwards' });
                await Promise.allSettled([fade.finished, backdrop.finished]);
                viewer.close();
                fade.cancel();
                backdrop.cancel();
                flip.cancel();
            } else {
                viewer.close();
            }
        } finally {
            viewer.classList.remove('viewer-fading');
            closingViewer = false;
        }
    }

    viewer.addEventListener('cancel', event => {
        event.preventDefault();
        if (closingViewer) return;
        if (activePane) setPhotoPane('');
        else closeViewer();
    });
    viewer.addEventListener('keydown', event => {
        if (closingViewer || viewer.classList.contains('portrait-only') || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
        const pane = event.key === '1' ? 'info' : event.key === '2' ? 'comments' : '';
        if (pane || (event.key === 'Escape' && activePane)) {
            event.preventDefault();
            event.stopPropagation();
            if (!event.repeat) setPhotoPane(activePane === pane ? '' : pane);
            return;
        }
        if (event.key === ' ' && event.target.closest('button, a')) return;
        const direction = event.key === 'ArrowRight' ? 1 : (event.key === 'ArrowLeft' || event.key === ' ') ? -1 : 0;
        if (!direction) return;
        event.preventDefault();
        showPhoto(active + direction, direction);
    });
    viewer.addEventListener('click', event => { if (event.target === viewer) closeViewer(); });
    viewer.addEventListener('close', () => { navigation++; setPhotoPane(''); displayedPhoto = undefined; viewer.classList.remove('portrait-only'); image.removeAttribute('src'); });

    document.querySelector('.glide-field').addEventListener('click', event => {
        const tile = event.target.closest('.glide-tile');
        if (!tile || event.defaultPrevented || event.button || event.ctrlKey ||
            event.metaKey || event.shiftKey || event.altKey) return;
        const index = photos.findIndex(photo => photo.url === tile.href);
        if (index < 0) return;
        event.preventDefault();
        matches = photos;
        viewer.showModal();
        // Escape restores focus here. Keep this track drifting until the next deliberate focus.
        tile.setAttribute('data-glide-restored-focus', '');
        tile.addEventListener('focusout', () => tile.removeAttribute('data-glide-restored-focus'), { once: true });
        showPhoto(index, 1, true);
    });

    function openPanel(panel) {
        if (panel.open) return;
        panel.showModal();
        if (panel === archive) filter();
        if (reduced.matches) return;
        panel.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 220, easing: 'ease-out' });
        if (panel === about) playAboutEntrance();
    }

    const aboutPortrait = about.querySelector('img');
    aboutPortrait.closest('button').addEventListener('click', () => {
        viewer.classList.add('portrait-only');
        image.src = aboutPortrait.src;
        image.alt = aboutPortrait.alt;
        viewer.querySelector('figcaption').textContent = 'Sean McCormick';
        viewer.showModal();
        if (!reduced.matches) image.animate([{ opacity: 0 }, { opacity: 1 }], { duration: 180 });
    });
    const portraitTurn = angle => 'perspective(1000px) rotateY(' + angle + 'deg)';
    const closingPanels = new Set();

    function playAboutEntrance() {
        aboutPortrait.animate([
            { transform: portraitTurn(-75), opacity: 0 },
            { transform: portraitTurn(0), opacity: 1 },
        ], { delay: 220, duration: 420, easing: 'cubic-bezier(.2,.7,.2,1)', fill: 'backwards' });
    }

    function spinArchiveTiles() {
        const header = archive.querySelector('header').getBoundingClientRect();
        const bottom = archive.getBoundingClientRect().bottom;
        const visible = Array.from(grid.children).filter(tile => {
            const bounds = tile.getBoundingClientRect();
            return bounds.bottom > header.bottom && bounds.top < bottom &&
                Number(getComputedStyle(tile).opacity) > 0 && !grid.classList.contains('portal-awaiting-photos');
        });
        const first = Array.from(grid.children).indexOf(visible[0]);
        return visible.map((tile, offset) => tile.animate([
            { transform: getComputedStyle(tile).transform, opacity: getComputedStyle(tile).opacity },
            { transform: 'perspective(900px) rotateY(90deg)', opacity: 0 },
        ], {
            delay: Math.floor(((first % 5) + offset) / 5) * 100 + ((first + offset) % 5) * 20,
            duration: 200, easing: 'ease-in', fill: 'forwards',
        }));
    }

    async function closePanel(panel) {
        if (closingPanels.has(panel) || !panel.open) return;
        if (reduced.matches) {
            panel.close();
            return;
        }
        closingPanels.add(panel);
        if (panel === archive) grid.inert = true;
        const turns = panel === archive ? spinArchiveTiles() : panel === about ? [aboutPortrait.animate([
            { transform: portraitTurn(0), opacity: 1 },
            { transform: portraitTurn(-75), opacity: 0 },
        ], { duration: 300, easing: 'ease-in', fill: 'forwards' })] : [];
        let fade;
        try {
            await Promise.allSettled(turns.map(turn => turn.finished));
            fade = panel.animate([{ opacity: getComputedStyle(panel).opacity }, { opacity: 0 }],
                { duration: panel === archive ? 150 : 200, easing: 'ease-in', fill: 'forwards' });
            await fade.finished;
        } finally {
            panel.close();
            turns.forEach(turn => turn.cancel());
            fade?.cancel();
            if (panel === archive) grid.inert = false;
            closingPanels.delete(panel);
        }
    }

    for (const panel of [archive, blogroll, about]) {
        panel.addEventListener('submit', event => {
            event.preventDefault();
            closePanel(panel);
        });
        panel.addEventListener('cancel', event => {
            event.preventDefault();
            closePanel(panel);
        });
        panel.addEventListener('click', event => {
            if (event.target !== panel) return;
            const bounds = panel.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right ||
                event.clientY < bounds.top || event.clientY > bounds.bottom) closePanel(panel);
        });
    }

    if (document.fullscreenEnabled && document.documentElement.requestFullscreen) {
        const nav = document.querySelector('.glide-nav .ss-grid-nav-links');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ss-grid-nav-link portal-fullscreen';
        button.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path/></svg>';
        const icon = button.querySelector('path');
        function updateFullscreen() {
            const active = Boolean(document.fullscreenElement);
            const label = active ? 'Exit fullscreen' : 'Enter fullscreen';
            button.title = label;
            button.setAttribute('aria-label', label);
            icon.setAttribute('d', active ? 'M4 9h5V4m11 5h-5V4M4 15h5v5m11-5h-5v5' : 'M9 4H4v5m11-5h5v5M4 15v5h5m11-5v5h-5');
        }
        button.addEventListener('click', async () => {
            try {
                if (document.fullscreenElement) await document.exitFullscreen();
                else await document.documentElement.requestFullscreen();
            } catch {
                updateFullscreen();
            }
        });
        document.addEventListener('fullscreenchange', updateFullscreen);
        updateFullscreen();
        nav.append(button);
    }

    for (const [name, panel, title] of [['archive', archive, 'Archive View'], ['blogroll', blogroll, 'Blogroll'], ['about', about, 'About']]) {
        const link = document.querySelector(`.glide-nav [title="${title}"]`);
        link.href = `#${name}`;
        link.addEventListener('click', event => {
            if (event.button || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            history.replaceState(null, '', link.href);
            openPanel(panel);
        });
        panel.addEventListener('close', () => history.replaceState(null, '', location.pathname));
        const openFromHash = () => { if (location.hash === link.hash) openPanel(panel); };
        window.addEventListener('hashchange', openFromHash);
        openFromHash();
    }
})();
// ===== SNAPSMACK EOF =====
