// SNAPSMACK_EOF_HEADER
// Last non-empty line must be the canonical JavaScript EOF marker below.

function coldSnapRenderMosaic(model) {
    const preview = document.getElementById('preview');
    if (!model.images.length) {
        const message = document.createElement('p');
        message.className = 'cold-mosaic-hint';
        message.textContent = 'Select photos to preview the mosaic.';
        preview.replaceChildren(message);
        return { items: [], height: 0 };
    }

    const grid = document.createElement('div');
    grid.style.width = model.width + 'px';
    preview.replaceChildren(grid);
    const wallClasses = {
        columns: 'ss-masonry', rows: 'ss-scroll-wall', square: 'ss-square-wall',
        masonry: 'ss-masonry', 'one-column': 'ss-masonry',
    };
    const wall = !model.images.some(photo => photo.weight) && wallClasses[model.layout];
    let sections = [];
    if (!wall) {
        grid.className = 'snap-mosaic preview-grid';
        grid.dataset.mosaic = JSON.stringify(model.images);
        grid.dataset.gap = String(model.gap);
        if (model.mirroredSections?.length) grid.dataset.mirrorSections = model.mirroredSections.join(',');
        if (model.layout !== 'asymmetric' && !wallClasses[model.layout]) grid.dataset.template = model.layout;
        const layout = window.SnapMosaic.renderMosaic(grid);
        if (layout) {
            let start = 0;
            sections = layout.sections.map(section => {
                const count = layout.items.filter(item =>
                    item.y >= section.y && item.y < section.y + section.height
                ).length;
                const group = { start, count };
                start += count;
                return group;
            });
        }
    } else {
        grid.className = wall + ' snap-mosaic-wall preview-grid';
        grid.style.setProperty('--ss-gap', model.gap + 'px');
        if (model.layout === 'masonry') grid.style.setProperty('--ss-cols', '3');
        if (model.layout === 'one-column') grid.style.setProperty('--ss-cols', '1');
        for (const photo of model.images) {
            const tile = document.createElement('div');
            tile.className = 'ss-masonry-item';
            const image = document.createElement('img');
            image.dataset.assetId = photo.id;
            image.dataset.w = photo.width;
            image.dataset.h = photo.height;
            image.alt = photo.alt;
            image.src = photo.src;
            tile.append(image);
            grid.append(tile);
        }
        if (wall === 'ss-masonry') window.SSColumns.layout(grid);
        if (wall === 'ss-scroll-wall') window.SSRows.layout(grid);
    }

    const parent = grid.getBoundingClientRect();
    const items = Array.from(grid.querySelectorAll('img[data-asset-id]'), (image) => {
        const tile = image.closest('.mosaic-item, .ss-masonry-item').getBoundingClientRect();
        return {
            id: Number(image.dataset.assetId), x: tile.x - parent.x, y: tile.y - parent.y,
            width: tile.width, height: tile.height,
        };
    });
    return { items, height: parent.height, sections };
}

// ===== SNAPSMACK EOF =====
