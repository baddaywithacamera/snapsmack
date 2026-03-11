/**
 * SnapSmack Gallery Slider Engine
 * Alpha v0.7.1
 *
 * A lightweight, dependency-free horizontal gallery slider component.
 * Supports two operating modes, selected via data-slider-mode on the container:
 *
 *   data-slider-mode="landing"  (default)
 *     Multi-slide filmstrip view. Used by Galleria/H2BS landing pages.
 *     perView slides visible at once. Panorama-aware: images wider than
 *     panoThreshold automatically get full-width slides.
 *
 *   data-slider-mode="carousel"
 *     Single-post carousel view. One slide visible at a time. Dot indicators
 *     rendered below the track. Dispatches 'snapslider:slidechange' custom
 *     event on each transition with { imageId, index, total } in detail so
 *     the host page can update an EXIF panel. Reads per-image EXIF from
 *     data-exif-map JSON attribute on the container (keyed by image ID).
 *
 * Usage (landing mode):
 *   var slider = new SnapSlider({
 *     container: document.getElementById('my-slider'),
 *     perView: 3,
 *     speed: 800,
 *     loop: true,
 *     panoThreshold: 2.0
 *   });
 *
 * Usage (carousel mode):
 *   var slider = new SnapSlider({
 *     container: document.getElementById('my-carousel'),
 *     speed: 400,
 *     loop: false
 *   });
 *   // data-slider-mode="carousel" on the container element activates carousel mode.
 */

(function(window) {
  'use strict';

  function SnapSlider(options) {
    options = options || {};

    // Carousel mode: read from data attribute first, allow override via options
    var modeAttr = options.container
      ? (options.container.getAttribute('data-slider-mode') || 'landing')
      : 'landing';
    this.mode = options.mode || modeAttr; // 'landing' | 'carousel'

    // Configuration
    this.container = options.container;
    // Carousel mode always shows 1 slide at a time regardless of perView option
    this.perView = (this.mode === 'carousel') ? 1 : (options.perView || 1);
    this.speed = options.speed || 800;
    this.easing = options.easing || 'ease-in-out';
    this.autoAdvance = options.autoAdvance || false;
    this.autoInterval = options.autoInterval || 5000;
    this.loop = options.loop !== false;
    this.panoThreshold = options.panoThreshold || 2.0;

    // Carousel mode: dot indicator element + EXIF map
    this.dotsElement = null;
    this.exifMap = {};
    if (this.mode === 'carousel' && this.container) {
      var exifAttr = this.container.getAttribute('data-exif-map');
      if (exifAttr) {
        try { this.exifMap = JSON.parse(exifAttr); } catch(e) {}
      }
    }

    // State
    this.currentIndex = 0;
    this.totalSlides = 0;
    this.isAnimating = false;
    this.autoPlayTimer = null;
    this.trackElement = null;
    this.slides = [];

    // Per-slide layout (panorama-aware)
    this.slideSpans = [];    // 1 = normal, perView = full-width pano
    this.slideWidths = [];   // computed px width per slide
    this.slideOffsets = [];  // cumulative px offset to each slide

    // Binding
    this._onNextClick = this._onNextClick.bind(this);
    this._onPrevClick = this._onPrevClick.bind(this);
    this._onKeyDown = this._onKeyDown.bind(this);
    this._onPointerDown = this._onPointerDown.bind(this);
    this._onPointerMove = this._onPointerMove.bind(this);
    this._onPointerUp = this._onPointerUp.bind(this);
    this._onWindowResize = this._onWindowResize.bind(this);
    this._onContainerMouseEnter = this._onContainerMouseEnter.bind(this);
    this._onContainerMouseLeave = this._onContainerMouseLeave.bind(this);

    // Swipe tracking
    this.pointerStart = { x: 0, y: 0 };
    this.pointerCurrent = { x: 0, y: 0 };
    this.isPointerDown = false;
    this.swipeThreshold = 50;

    this._init();
  }

  /**
   * Initialize the slider
   */
  SnapSlider.prototype._init = function() {
    if (!this.container) {
      console.error('SnapSlider: container element not provided');
      return;
    }

    // Add main slider class
    this.container.classList.add('ss-slider');

    // Set CSS custom property for perView (used by base CSS as fallback)
    this.container.style.setProperty('--per-view', this.perView);

    // Find track element
    this.trackElement = this.container.querySelector('.slider-track');
    if (!this.trackElement) {
      console.error('SnapSlider: .slider-track element not found inside container');
      return;
    }

    // Get all slides
    this.slides = Array.from(this.trackElement.children);
    this.totalSlides = this.slides.length;

    if (this.totalSlides === 0) {
      console.warn('SnapSlider: no slides found');
      return;
    }

    // Add slide class to each slide
    this.slides.forEach(function(slide) {
      slide.classList.add('slider-slide');
    });

    // Analyze aspect ratios and assign spans
    this._analyzeSlides();

    // Set initial transform state
    this.trackElement.style.transition = 'none';
    this.trackElement.style.transform = 'translateX(0)';

    // Create navigation arrows
    this._createArrows();

    // Carousel mode: create dot indicators below the track
    if (this.mode === 'carousel') {
      this._createDots();
    }

    // Calculate dimensions (uses slideSpans from _analyzeSlides)
    this._calculateDimensions();

    // Attach event listeners
    this._attachEventListeners();

    // Start auto-advance if enabled
    if (this.autoAdvance) {
      this._startAutoPlay();
    }
  };

  /**
   * Scan each slide's image for aspect ratio.
   * Images wider than panoThreshold get full-width treatment (span = perView).
   * Handles lazy-loaded images by re-analyzing when they finish loading.
   */
  SnapSlider.prototype._analyzeSlides = function() {
    var self = this;
    this.slideSpans = [];

    this.slides.forEach(function(slide, i) {
      var img = slide.querySelector('img');
      if (!img) {
        self.slideSpans[i] = 1;
        return;
      }

      // If the image is already loaded, check its dimensions
      if (img.naturalWidth && img.naturalHeight) {
        var ratio = img.naturalWidth / img.naturalHeight;
        self.slideSpans[i] = self._spanForRatio(ratio);
        self._classifySlide(slide, ratio);
      } else {
        // Not loaded yet — default to 1, re-check on load
        self.slideSpans[i] = 1;
        img.addEventListener('load', function() {
          var ratio = img.naturalWidth / img.naturalHeight;
          var newSpan = self._spanForRatio(ratio);
          self._classifySlide(slide, ratio);
          if (newSpan !== self.slideSpans[i]) {
            self.slideSpans[i] = newSpan;
            self._calculateDimensions();
            self._updateSlidePosition(false);
          }
        });
      }
    });
  };

  /**
   * Tag a slide with orientation data for CSS proportional scaling.
   * portrait (<0.9), landscape (0.9–panoThreshold), pano (>=panoThreshold)
   */
  SnapSlider.prototype._classifySlide = function(slide, ratio) {
    if (ratio < 0.9) {
      slide.setAttribute('data-orient', 'portrait');
    } else if (ratio >= this.panoThreshold) {
      slide.setAttribute('data-orient', 'pano');
    } else {
      slide.setAttribute('data-orient', 'landscape');
    }
  };

  /**
   * Determine how many "slots" a slide should span based on aspect ratio.
   * Returns 1 for normal images, perView for panoramas (full container width).
   */
  SnapSlider.prototype._spanForRatio = function(ratio) {
    if (this.perView <= 1) return 1;
    return (ratio >= this.panoThreshold) ? this.perView : 1;
  };

  /**
   * Create navigation arrow elements
   */
  SnapSlider.prototype._createArrows = function() {
    var leftArrow = document.createElement('button');
    leftArrow.className = 'slider-arrow slider-arrow-left';
    leftArrow.setAttribute('aria-label', 'Previous slide');
    leftArrow.innerHTML = '<span></span>';
    leftArrow.addEventListener('click', this._onPrevClick);
    this.container.appendChild(leftArrow);

    var rightArrow = document.createElement('button');
    rightArrow.className = 'slider-arrow slider-arrow-right';
    rightArrow.setAttribute('aria-label', 'Next slide');
    rightArrow.innerHTML = '<span></span>';
    rightArrow.addEventListener('click', this._onNextClick);
    this.container.appendChild(rightArrow);
  };

  /**
   * Calculate per-slide widths and cumulative offsets.
   * Normal slides get containerWidth / perView.
   * Panoramic slides get containerWidth (full width).
   */
  SnapSlider.prototype._calculateDimensions = function() {
    var containerWidth = this.container.offsetWidth;
    var unitWidth = containerWidth / this.perView;

    this.slideWidths = [];
    this.slideOffsets = [];
    var offset = 0;

    for (var i = 0; i < this.totalSlides; i++) {
      var span = this.slideSpans[i] || 1;
      var w = unitWidth * span;
      this.slideWidths[i] = w;
      this.slideOffsets[i] = offset;

      // Override CSS width on this slide element
      this.slides[i].style.width = w + 'px';

      offset += w;
    }

    // Set track total width so flexbox doesn't compress slides
    this.trackElement.style.width = offset + 'px';
  };

  /**
   * Attach all event listeners
   */
  SnapSlider.prototype._attachEventListeners = function() {
    this.trackElement.addEventListener('pointerdown', this._onPointerDown, false);
    document.addEventListener('pointermove', this._onPointerMove, false);
    document.addEventListener('pointerup', this._onPointerUp, false);
    document.addEventListener('keydown', this._onKeyDown, false);
    window.addEventListener('resize', this._onWindowResize, false);
    this.container.addEventListener('mouseenter', this._onContainerMouseEnter, false);
    this.container.addEventListener('mouseleave', this._onContainerMouseLeave, false);
  };

  /**
   * Remove all event listeners
   */
  SnapSlider.prototype._detachEventListeners = function() {
    this.trackElement.removeEventListener('pointerdown', this._onPointerDown, false);
    document.removeEventListener('pointermove', this._onPointerMove, false);
    document.removeEventListener('pointerup', this._onPointerUp, false);
    document.removeEventListener('keydown', this._onKeyDown, false);
    window.removeEventListener('resize', this._onWindowResize, false);
    this.container.removeEventListener('mouseenter', this._onContainerMouseEnter, false);
    this.container.removeEventListener('mouseleave', this._onContainerMouseLeave, false);
  };

  /**
   * Handle next arrow click
   */
  SnapSlider.prototype._onNextClick = function() {
    this.next();
  };

  /**
   * Handle previous arrow click
   */
  SnapSlider.prototype._onPrevClick = function() {
    this.prev();
  };

  /**
   * Handle keyboard navigation
   */
  SnapSlider.prototype._onKeyDown = function(e) {
    if (!this._isElementInViewport(this.container)) {
      return;
    }

    if (e.key === 'ArrowLeft' || e.key === 'Left') {
      this.prev();
    } else if (e.key === 'ArrowRight' || e.key === 'Right') {
      this.next();
    }
  };

  /**
   * Check if element is in viewport (simple check)
   */
  SnapSlider.prototype._isElementInViewport = function(el) {
    var rect = el.getBoundingClientRect();
    return (
      rect.top < window.innerHeight &&
      rect.bottom > 0 &&
      rect.left < window.innerWidth &&
      rect.right > 0
    );
  };

  /**
   * Handle pointer down (touch/mouse)
   */
  SnapSlider.prototype._onPointerDown = function(e) {
    this.isPointerDown = true;
    this.pointerStart.x = e.clientX || e.touches[0].clientX;
    this.pointerStart.y = e.clientY || e.touches[0].clientY;
    this.pointerCurrent.x = this.pointerStart.x;
    this.pointerCurrent.y = this.pointerStart.y;

    // Pause auto-advance during interaction
    this._stopAutoPlay();
  };

  /**
   * Handle pointer move (touch/mouse)
   */
  SnapSlider.prototype._onPointerMove = function(e) {
    if (!this.isPointerDown) {
      return;
    }

    this.pointerCurrent.x = e.clientX || e.touches[0].clientX;
    this.pointerCurrent.y = e.clientY || e.touches[0].clientY;
  };

  /**
   * Handle pointer up (touch/mouse)
   */
  SnapSlider.prototype._onPointerUp = function(e) {
    if (!this.isPointerDown) {
      return;
    }

    this.isPointerDown = false;

    var deltaX = this.pointerStart.x - this.pointerCurrent.x;
    var deltaY = Math.abs(this.pointerStart.y - this.pointerCurrent.y);

    // Only register swipe if horizontal movement is significant
    // and vertical movement is minimal (not scrolling)
    if (Math.abs(deltaX) > this.swipeThreshold && deltaY < Math.abs(deltaX) / 2) {
      if (deltaX > 0) {
        this.next();
      } else {
        this.prev();
      }
    }

    // Resume auto-advance
    if (this.autoAdvance) {
      this._startAutoPlay();
    }
  };

  /**
   * Handle window resize
   */
  SnapSlider.prototype._onWindowResize = function() {
    this._calculateDimensions();
    this._updateSlidePosition(false);
  };

  /**
   * Handle container mouse enter (stop auto-advance)
   */
  SnapSlider.prototype._onContainerMouseEnter = function() {
    this._stopAutoPlay();
  };

  /**
   * Handle container mouse leave (resume auto-advance)
   */
  SnapSlider.prototype._onContainerMouseLeave = function() {
    if (this.autoAdvance) {
      this._startAutoPlay();
    }
  };

  /**
   * Find the first slide of the next "page" — the first slide that isn't
   * fully visible from the current position. This ensures panos (and any
   * other wide slides) always snap to their own view, never partially shown.
   */
  SnapSlider.prototype._nextPageIndex = function() {
    var containerWidth = this.container.offsetWidth;
    var currentOffset = this.slideOffsets[this.currentIndex] || 0;
    var edge = currentOffset + containerWidth;

    // Find first slide whose right edge exceeds the visible area
    for (var i = this.currentIndex + 1; i < this.totalSlides; i++) {
      var slideRight = this.slideOffsets[i] + this.slideWidths[i];
      if (slideRight > edge + 1) { // +1 for rounding tolerance
        return i;
      }
    }
    // All remaining slides fit — we're at the end
    return -1;
  };

  /**
   * Find the first slide of the previous "page" — step back by one
   * container width from the current position.
   */
  SnapSlider.prototype._prevPageIndex = function() {
    var containerWidth = this.container.offsetWidth;
    var currentOffset = this.slideOffsets[this.currentIndex] || 0;
    var target = currentOffset - containerWidth;
    if (target <= 0) return 0;

    // Find the slide that contains this target offset
    for (var i = this.totalSlides - 1; i >= 0; i--) {
      if (this.slideOffsets[i] <= target + 1) {
        return i;
      }
    }
    return 0;
  };

  /**
   * Navigate to next page of slides
   */
  SnapSlider.prototype.next = function() {
    if (this.isAnimating || this.totalSlides === 0) {
      return;
    }

    var nextIndex = this._nextPageIndex();

    if (nextIndex === -1) {
      if (this.loop) {
        this.goTo(0);
      }
      return;
    }

    this.goTo(nextIndex);
  };

  /**
   * Navigate to previous page of slides
   */
  SnapSlider.prototype.prev = function() {
    if (this.isAnimating || this.totalSlides === 0) {
      return;
    }

    var prevIndex = this._prevPageIndex();

    if (prevIndex >= this.currentIndex) {
      // Already at or past the beginning
      if (this.loop) {
        this.goTo(this.totalSlides - 1);
      }
      return;
    }

    this.goTo(prevIndex);
  };

  /**
   * Go to specific slide index
   */
  SnapSlider.prototype.goTo = function(index) {
    if (this.isAnimating || this.totalSlides === 0) {
      return;
    }

    // Clamp index
    index = Math.max(0, Math.min(index, this.totalSlides - 1));

    if (index === this.currentIndex) {
      return;
    }

    this.currentIndex = index;
    this._updateSlidePosition(true);
    this._dispatchChangeEvent();
  };

  /**
   * Update slide position using cumulative offsets (panorama-aware).
   * Each slide may have a different width, so offset is looked up
   * from the slideOffsets array instead of multiplying by a uniform width.
   *
   * When the remaining slides from currentIndex to the end don't fill the
   * container width, they are centered horizontally for a polished look.
   */
  SnapSlider.prototype._updateSlidePosition = function(animate) {
    var containerWidth = this.container.offsetWidth;
    var lastSlide = this.totalSlides - 1;
    var totalTrackWidth = this.slideOffsets[lastSlide] + this.slideWidths[lastSlide];

    // Width of all slides from currentIndex to end
    var remainingWidth = totalTrackWidth - (this.slideOffsets[this.currentIndex] || 0);

    var offset;
    if (totalTrackWidth <= containerWidth) {
      // Fewer slides than can fill the view — center the whole track
      offset = (containerWidth - totalTrackWidth) / 2;
    } else if (remainingWidth < containerWidth) {
      // End of track: center the remaining slides within the container
      var gap = containerWidth - remainingWidth;
      offset = -(this.slideOffsets[this.currentIndex] || 0) + (gap / 2);
    } else {
      // Normal: position current slide at left edge
      offset = -(this.slideOffsets[this.currentIndex] || 0);
    }

    if (animate) {
      this.isAnimating = true;
      this.trackElement.style.transition = 'transform ' + this.speed + 'ms ' + this.easing;
    } else {
      this.trackElement.style.transition = 'none';
    }

    this.trackElement.style.transform = 'translateX(' + offset + 'px)';

    if (animate) {
      var self = this;
      setTimeout(function() {
        self.isAnimating = false;
      }, this.speed);
    }
  };

  /**
   * Dispatch slide-change events and update carousel dots.
   *
   * Always fires 'slide-change' (legacy, landing mode).
   * In carousel mode, also fires 'snapslider:slidechange' with:
   *   { index, total, imageId, exif }
   * The host page listens for snapslider:slidechange to update an EXIF panel.
   */
  SnapSlider.prototype._dispatchChangeEvent = function() {
    // Legacy event (landing mode consumers listen to this)
    var legacyEvent = new CustomEvent('slide-change', {
      detail: { index: this.currentIndex, total: this.totalSlides }
    });
    this.container.dispatchEvent(legacyEvent);

    // Carousel mode: emit richer event and update dots
    if (this.mode === 'carousel') {
      var slide = this.slides[this.currentIndex];
      var imageId = slide ? (slide.getAttribute('data-image-id') || '') : '';
      var exif = imageId ? (this.exifMap[imageId] || {}) : {};

      var carouselEvent = new CustomEvent('snapslider:slidechange', {
        bubbles: true,
        detail: {
          index:   this.currentIndex,
          total:   this.totalSlides,
          imageId: imageId,
          exif:    exif
        }
      });
      this.container.dispatchEvent(carouselEvent);

      this._updateDots();
    }
  };

  /**
   * Create dot indicator strip for carousel mode.
   * Inserts a <div class="ss-slider-dots"> immediately after the container.
   * Dots are always rendered; active dot gets .is-active class.
   */
  SnapSlider.prototype._createDots = function() {
    if (this.totalSlides < 2) return; // no dots for single-image posts

    var self = this;
    var wrap = document.createElement('div');
    wrap.className = 'ss-slider-dots';

    for (var i = 0; i < this.totalSlides; i++) {
      (function(idx) {
        var dot = document.createElement('button');
        dot.className = 'ss-slider-dot' + (idx === 0 ? ' is-active' : '');
        dot.setAttribute('aria-label', 'Go to image ' + (idx + 1));
        dot.addEventListener('click', function() { self.goTo(idx); });
        wrap.appendChild(dot);
      })(i);
    }

    // Insert after the slider container
    this.container.parentNode.insertBefore(wrap, this.container.nextSibling);
    this.dotsElement = wrap;
  };

  /**
   * Update the active dot to reflect currentIndex.
   */
  SnapSlider.prototype._updateDots = function() {
    if (!this.dotsElement) return;
    var dots = this.dotsElement.querySelectorAll('.ss-slider-dot');
    for (var i = 0; i < dots.length; i++) {
      dots[i].classList.toggle('is-active', i === this.currentIndex);
    }
  };

  /**
   * Start auto-advance interval
   */
  SnapSlider.prototype._startAutoPlay = function() {
    if (this.autoPlayTimer) {
      return;
    }

    var self = this;
    this.autoPlayTimer = setInterval(function() {
      self.next();
    }, self.autoInterval);
  };

  /**
   * Stop auto-advance interval
   */
  SnapSlider.prototype._stopAutoPlay = function() {
    if (this.autoPlayTimer) {
      clearInterval(this.autoPlayTimer);
      this.autoPlayTimer = null;
    }
  };

  /**
   * Destroy slider and clean up
   */
  SnapSlider.prototype.destroy = function() {
    this._stopAutoPlay();
    this._detachEventListeners();

    // Remove arrows
    var arrows = this.container.querySelectorAll('.slider-arrow');
    arrows.forEach(function(arrow) {
      arrow.parentNode.removeChild(arrow);
    });

    // Remove dots (carousel mode)
    if (this.dotsElement && this.dotsElement.parentNode) {
      this.dotsElement.parentNode.removeChild(this.dotsElement);
      this.dotsElement = null;
    }

    // Remove slider classes
    this.container.classList.remove('ss-slider');
    this.slides.forEach(function(slide) {
      slide.classList.remove('slider-slide');
      slide.style.width = '';
    });

    // Reset styles
    this.trackElement.style.transition = '';
    this.trackElement.style.transform = '';
    this.trackElement.style.width = '';
    this.container.style.setProperty('--per-view', '');

    // Clear state
    this.slides = [];
    this.trackElement = null;
    this.currentIndex = 0;
    this.totalSlides = 0;
    this.slideSpans = [];
    this.slideWidths = [];
    this.slideOffsets = [];
  };

  // Expose to window
  window.SnapSlider = SnapSlider;

})(window);
