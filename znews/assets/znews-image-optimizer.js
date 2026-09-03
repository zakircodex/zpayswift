((root) => {
  'use strict';

  const ALLOWED_TYPES = new Set(['image/jpeg', 'image/png', 'image/webp']);
  const MAX_INPUT_BYTES = 8 * 1024 * 1024;
  const TARGET_BYTES = 500 * 1024;
  const MAX_OUTPUT_BYTES = 700 * 1024;
  const MAX_EDGE = 1600;

  function outputName(name, mime) {
    const base = String(name || 'zsky-photo').replace(/\.[^.]+$/, '').replace(/[^A-Za-z0-9_-]+/g, '-');
    return `${base || 'zsky-photo'}.${mime === 'image/webp' ? 'webp' : mime === 'image/png' ? 'png' : 'jpg'}`;
  }

  function canvasBlob(canvas, mime, quality) {
    return new Promise((resolve, reject) => {
      canvas.toBlob((blob) => {
        if (blob) resolve(blob);
        else reject(new Error('Photo optimization failed.'));
      }, mime, quality);
    });
  }

  async function decode(file) {
    if (typeof createImageBitmap === 'function') {
      return createImageBitmap(file, { imageOrientation: 'from-image' });
    }
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const image = new Image();
      image.onload = () => {
        URL.revokeObjectURL(url);
        resolve(image);
      };
      image.onerror = () => {
        URL.revokeObjectURL(url);
        reject(new Error('The selected photo could not be read.'));
      };
      image.src = url;
    });
  }

  function supportsWebp() {
    const canvas = document.createElement('canvas');
    canvas.width = 1;
    canvas.height = 1;
    return canvas.toDataURL('image/webp').startsWith('data:image/webp');
  }

  function draw(source, edge) {
    const sourceWidth = Number(source.width || source.naturalWidth || 0);
    const sourceHeight = Number(source.height || source.naturalHeight || 0);
    const scale = Math.min(1, edge / Math.max(sourceWidth, sourceHeight));
    const width = Math.max(1, Math.round(sourceWidth * scale));
    const height = Math.max(1, Math.round(sourceHeight * scale));
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext('2d', { alpha: true });
    if (!context) throw new Error('Photo optimization is unavailable.');
    context.imageSmoothingEnabled = true;
    context.imageSmoothingQuality = 'high';
    context.drawImage(source, 0, 0, width, height);
    return { canvas, width, height };
  }

  async function optimize(file, onStatus = () => {}) {
    if (!(file instanceof Blob) || !ALLOWED_TYPES.has(String(file.type || '').toLowerCase())) {
      throw new Error('Choose a JPEG, PNG or WebP photo.');
    }
    if (file.size <= 0 || file.size > MAX_INPUT_BYTES) {
      throw new Error('Image must be 8 MB or smaller.');
    }
    onStatus('Optimizing photo...');
    await new Promise((resolve) => setTimeout(resolve, 0));
    const source = await decode(file);
    try {
      const width = Number(source.width || source.naturalWidth || 0);
      const height = Number(source.height || source.naturalHeight || 0);
      if (!width || !height) {
        throw new Error('The selected photo has invalid dimensions.');
      }

      const outputMime = supportsWebp() ? 'image/webp' : (file.type === 'image/webp' ? 'image/jpeg' : file.type);
      const edges = [1600, 1440, 1280, 1120, 960, 800];
      const qualities = outputMime === 'image/png' ? [1] : [0.82, 0.76, 0.7, 0.64, 0.58];
      let best = null;
      outer: for (const edge of edges) {
        const rendered = draw(source, Math.min(MAX_EDGE, edge));
        for (const quality of qualities) {
          const blob = await canvasBlob(rendered.canvas, outputMime, quality);
          if (!best || blob.size < best.blob.size) best = { blob, width: rendered.width, height: rendered.height };
          if (blob.size <= TARGET_BYTES) break outer;
        }
        await new Promise((resolve) => setTimeout(resolve, 0));
      }
      if (!best || best.blob.size > MAX_OUTPUT_BYTES) {
        throw new Error('Photo could not be reduced below 700 KB. Choose another photo.');
      }
      const optimized = new File([best.blob], outputName(file.name, outputMime), {
        type: outputMime,
        lastModified: Date.now()
      });
      return Object.freeze({
        file: optimized,
        originalBytes: file.size,
        finalBytes: optimized.size,
        width: best.width,
        height: best.height,
        compressionPercent: Math.max(0, Math.round((1 - (optimized.size / file.size)) * 100))
      });
    } finally {
      source.close?.();
    }
  }

  root.ZNewsImageOptimizer = Object.freeze({
    optimize,
    allowedTypes: Object.freeze([...ALLOWED_TYPES]),
    maxInputBytes: MAX_INPUT_BYTES,
    targetBytes: TARGET_BYTES,
    maxOutputBytes: MAX_OUTPUT_BYTES,
    maxEdge: MAX_EDGE
  });
})(typeof window !== 'undefined' ? window : globalThis);
