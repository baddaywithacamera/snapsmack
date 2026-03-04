/**
 * SnapSmack Gallery Slider Engine
 * A lightweight, dependency-free horizontal gallery slider component
 *
 * Usage:
 *   var slider = new SnapSlider({
 *     container: document.getElementById('my-slider'),
 *     perView: 3,
 *     speed: 800,
 *     easing: 'ease-in-out',
 *     autoAdvance: false,
 *     autoInterval: 5000,
 *     loop: true
 *   });
 */

(function(window) {
  'use strict';

  function SnapSlider(options) {
    options = options || {};

    // Configuration
    this.container = options.container;
    this.perView = options.perView || 1;
    this.speed = options.speed || 800;
    this.easing = options.easing || 'ease-in-out';
    this.autoAdvance = options.autoAdvance || false;
    this.autoInterval = options.autoInterval || 5000;
    this.loop = options.loop !== false;

    // State
    this.currentIndex = 0;
    this.totalSlides = 0;
    this.isAnimating = false;
    this.autoPlayTimer = null;
    this.trackElement = null;
    this.slides = [];
    this.slideWidth = 0;

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

    // Set CSS custom property for perView
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

    // Set initial transform state
    this.trackElement.style.transition = 'none';
    this.trackElement.style.transform = 'translateX(0)';

    // Create navigation arrows
    this._createArrows();

    // Calculate dimensions
    this._calculateDimensions();

    // Attach event listeners
    this._attachEventListeners();

    // Start auto-advance if enabled
    if (this.autoAdvance) {
      this._startAutoPlay();
    }
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
   * Calculate slide dimensions based on perView
   */
  SnapSlider.prototype._calculateDimensions = function() {
    var containerWidth = this.container.offsetWidth;
    this.slideWidth = containerWidth / this.perView;
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
   * Navigate to next slide
   */
  SnapSlider.prototype.next = function() {
    if (this.isAnimating || this.totalSlides === 0) {
      return;
    }

    var nextIndex = this.currentIndex + 1;

    if (nextIndex >= this.totalSlides) {
      if (this.loop) {
        this.goTo(0);
      }
      return;
    }

    this.goTo(nextIndex);
  };

  /**
   * Navigate to previous slide
   */
  SnapSlider.prototype.prev = function() {
    if (this.isAnimating || this.totalSlides === 0) {
      return;
    }

    var prevIndex = this.currentIndex - 1;

    if (prevIndex < 0) {
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
   * Update slide position with animation
   */
  SnapSlider.prototype._updateSlidePosition = function(animate) {
    var offset = -this.currentIndex * this.slideWidth;

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
   * Dispatch custom slide-change event
   */
  SnapSlider.prototype._dispatchChangeEvent = function() {
    var event = new CustomEvent('slide-change', {
      detail: {
        index: this.currentIndex,
        total: this.totalSlides
      }
    });
    this.container.dispatchEvent(event);
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
    }, this.autoInterval);
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

    // Remove slider classes
    this.container.classList.remove('ss-slider');
    this.slides.forEach(function(slide) {
      slide.classList.remove('slider-slide');
    });

    // Reset styles
    this.trackElement.style.transition = '';
    this.trackElement.style.transform = '';
    this.container.style.setProperty('--per-view', '');

    // Clear state
    this.slides = [];
    this.trackElement = null;
    this.currentIndex = 0;
    this.totalSlides = 0;
  };

  // Expose to window
  window.SnapSlider = SnapSlider;

})(window);
