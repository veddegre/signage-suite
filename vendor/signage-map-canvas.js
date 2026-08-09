/**
 * Leaflet map canvas helpers — cap DPR/FPS on Pi and other low-end kiosks.
 * ?mapperf=low|high overrides auto-detect.
 */
(function (global) {
  'use strict';

  var HIGH = {
    low: false,
    dprMax: 0,
    fps: 60,
    arcSteps: 56,
    shadowBlur: -1,
    heatLabelsMaxRank: 8,
    heatIntensityLabel: 0.45,
  };

  var LOW = {
    low: true,
    dprMax: 1,
    fps: 24,
    arcSteps: 28,
    shadowBlur: 0,
    heatLabelsMaxRank: 5,
    heatIntensityLabel: 0.55,
  };

  function detectLowEnd() {
    var q = new URLSearchParams(global.location.search);
    var force = q.get('mapperf');
    if (force === 'high') return false;
    if (force === 'low') return true;
    if (global.matchMedia && global.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      return true;
    }
    var ua = (global.navigator.userAgent || '') + ' ' + (global.navigator.platform || '');
    if (/raspberry|aarch64|armv7|armv8|linux arm/i.test(ua)) return true;
    var cores = global.navigator.hardwareConcurrency || 0;
    if (cores > 0 && cores <= 4 && /linux/i.test(ua)) return true;
    return false;
  }

  var profile = null;

  function getProfile() {
    if (!profile) profile = detectLowEnd() ? LOW : HIGH;
    return profile;
  }

  function canvasDpr() {
    var dpr = global.devicePixelRatio || 1;
    var max = getProfile().dprMax;
    return max > 0 ? Math.min(dpr, max) : dpr;
  }

  function frameIntervalMs() {
    return 1000 / getProfile().fps;
  }

  function resizeCanvas(canvas, cssW, cssH) {
    var dpr = canvasDpr();
    canvas.width = Math.round(cssW * dpr);
    canvas.height = Math.round(cssH * dpr);
    canvas.style.width = cssW + 'px';
    canvas.style.height = cssH + 'px';
    return dpr;
  }

  function bindAnimLoop(layer, drawFn) {
    var last = 0;
    var interval = frameIntervalMs();
    var tick = function (now) {
      layer._smcRaf = global.requestAnimationFrame(tick);
      if (now - last < interval) return;
      var t0 = global.performance.now();
      drawFn(now);
      var elapsed = global.performance.now() - t0;
      last = now;
      if (getProfile().low && elapsed > 35 && interval < 50) {
        interval = Math.min(50, interval + 3);
      }
    };
    layer._smcRaf = global.requestAnimationFrame(tick);
  }

  function unbindAnimLoop(layer) {
    if (layer._smcRaf) {
      global.cancelAnimationFrame(layer._smcRaf);
      layer._smcRaf = 0;
    }
  }

  function arcSteps() {
    return getProfile().arcSteps;
  }

  function glowBlur(defaultBlur) {
    var blur = getProfile().shadowBlur;
    return blur >= 0 ? blur : defaultBlur;
  }

  function shouldDrawHeatLabel(rank, intensity) {
    var p = getProfile();
    return rank <= p.heatLabelsMaxRank || intensity > p.heatIntensityLabel;
  }

  global.SignageMapCanvas = {
    profile: getProfile,
    canvasDpr: canvasDpr,
    resizeCanvas: resizeCanvas,
    bindAnimLoop: bindAnimLoop,
    unbindAnimLoop: unbindAnimLoop,
    arcSteps: arcSteps,
    glowBlur: glowBlur,
    shouldDrawHeatLabel: shouldDrawHeatLabel,
  };
})(typeof window !== 'undefined' ? window : globalThis);
