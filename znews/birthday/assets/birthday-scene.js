import * as THREE from './lib/three.module.js';

const MOON_TEXTURE = '/znews/birthday/assets/images/moon-surface-v1.webp';
const THEMES = Object.freeze({
  cosmic: { space: 0x02080d, star: 0xbfe9ff, glow: 0x63e6be, comet: 0xc9f8ff },
  dreamy: { space: 0x090711, star: 0xffd9e8, glow: 0xff8fa3, comet: 0xf7e6ff },
  celebration: { space: 0x0d0709, star: 0xffe3a0, glow: 0xff6b6b, comet: 0xfff0c2 }
});

const clamp = (value, minimum, maximum) => Math.max(minimum, Math.min(maximum, value));

function seedFrom(value) {
  let hash = 2166136261;
  for (const character of String(value || 'birthday-universe')) {
    hash ^= character.charCodeAt(0);
    hash = Math.imul(hash, 16777619);
  }
  return hash >>> 0;
}

function randomFrom(seed) {
  let state = seed >>> 0;
  return () => {
    state += 0x6D2B79F5;
    let value = state;
    value = Math.imul(value ^ (value >>> 15), value | 1);
    value ^= value + Math.imul(value ^ (value >>> 7), value | 61);
    return ((value ^ (value >>> 14)) >>> 0) / 4294967296;
  };
}

function qualityProfile() {
  const memory = Number(navigator.deviceMemory || 4);
  const cores = Number(navigator.hardwareConcurrency || 4);
  const compact = window.innerWidth < 390;
  if (memory <= 2 || cores <= 2) return { stars: 1800, burst: 360, segments: 32, dpr: 1 };
  if (memory <= 4 || cores <= 4 || compact) return { stars: 3200, burst: 900, segments: 48, dpr: 1.2 };
  return { stars: 6500, burst: 1600, segments: 64, dpr: 1.5 };
}

function radialTexture() {
  const canvas = document.createElement('canvas');
  canvas.width = 128;
  canvas.height = 128;
  const context = canvas.getContext('2d');
  if (!context) return null;
  const gradient = context.createRadialGradient(64, 64, 2, 64, 64, 62);
  gradient.addColorStop(0, 'rgba(255,255,255,.92)');
  gradient.addColorStop(.18, 'rgba(205,241,255,.54)');
  gradient.addColorStop(1, 'rgba(120,210,255,0)');
  context.fillStyle = gradient;
  context.fillRect(0, 0, 128, 128);
  const texture = new THREE.CanvasTexture(canvas);
  texture.colorSpace = THREE.SRGBColorSpace;
  return texture;
}

function createStarField(count, theme, random, particleTexture) {
  const positions = new Float32Array(count * 3);
  const colors = new Float32Array(count * 3);
  const base = new THREE.Color(theme.star);
  const warm = new THREE.Color(0xffd7a1);
  for (let index = 0; index < count; index += 1) {
    const radius = 18 + random() * 52;
    const theta = random() * Math.PI * 2;
    const phi = Math.acos(2 * random() - 1);
    positions[index * 3] = radius * Math.sin(phi) * Math.cos(theta);
    positions[index * 3 + 1] = radius * Math.sin(phi) * Math.sin(theta);
    positions[index * 3 + 2] = radius * Math.cos(phi) - 14;
    const color = base.clone().lerp(warm, random() * .34);
    const intensity = .5 + random() * .5;
    colors[index * 3] = color.r * intensity;
    colors[index * 3 + 1] = color.g * intensity;
    colors[index * 3 + 2] = color.b * intensity;
  }
  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
  geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
  const material = new THREE.PointsMaterial({
    size: window.innerWidth < 500 ? .15 : .18,
    sizeAttenuation: true,
    transparent: true,
    opacity: .96,
    vertexColors: true,
    map: particleTexture,
    alphaTest: .015,
    depthWrite: false,
    blending: THREE.AdditiveBlending
  });
  return new THREE.Points(geometry, material);
}

function createMoon(profile, theme, requestRender) {
  const group = new THREE.Group();
  const geometry = new THREE.SphereGeometry(1, profile.segments, Math.max(24, profile.segments / 2));
  const material = new THREE.MeshStandardMaterial({ color: 0xd8d8d3, roughness: .96, metalness: 0, bumpScale: .075 });
  const moon = new THREE.Mesh(geometry, material);
  moon.rotation.z = -.08;
  group.add(moon);

  const glowMap = radialTexture();
  if (glowMap) {
    const glow = new THREE.Sprite(new THREE.SpriteMaterial({ map: glowMap, color: theme.glow, transparent: true, opacity: .38, depthWrite: false, blending: THREE.AdditiveBlending }));
    glow.scale.set(3.15, 3.15, 1);
    glow.position.z = -.45;
    group.add(glow);
  }

  new THREE.TextureLoader().load(MOON_TEXTURE, texture => {
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.wrapS = THREE.RepeatWrapping;
    texture.anisotropy = 4;
    material.map = texture;
    material.bumpMap = texture;
    material.needsUpdate = true;
    requestRender();
  }, undefined, () => requestRender());
  return { group, moon };
}

function createSatellite() {
  const group = new THREE.Group();
  const metal = new THREE.MeshStandardMaterial({ color: 0xc7d2d8, roughness: .45, metalness: .7 });
  const panel = new THREE.MeshStandardMaterial({ color: 0x1d6b94, roughness: .4, metalness: .35, emissive: 0x082d45, emissiveIntensity: .7 });
  const body = new THREE.Mesh(new THREE.BoxGeometry(.28, .18, .18), metal);
  const left = new THREE.Mesh(new THREE.BoxGeometry(.48, .03, .22), panel);
  const right = left.clone();
  left.position.x = -.4;
  right.position.x = .4;
  group.add(body, left, right);
  group.scale.setScalar(.55);
  return group;
}

function createComet(theme) {
  const group = new THREE.Group();
  const head = new THREE.Mesh(
    new THREE.SphereGeometry(.055, 12, 8),
    new THREE.MeshBasicMaterial({ color: theme.comet })
  );
  const tailPositions = new Float32Array(36 * 3);
  for (let index = 0; index < 36; index += 1) {
    tailPositions[index * 3] = -index * .055;
    tailPositions[index * 3 + 1] = Math.sin(index * .38) * .012;
  }
  const tailGeometry = new THREE.BufferGeometry();
  tailGeometry.setAttribute('position', new THREE.BufferAttribute(tailPositions, 3));
  const tail = new THREE.Line(tailGeometry, new THREE.LineBasicMaterial({ color: theme.comet, transparent: true, opacity: .46, blending: THREE.AdditiveBlending }));
  group.add(head, tail);
  return group;
}

function createBurst(count, theme, random, particleTexture) {
  const positions = new Float32Array(count * 3);
  const velocities = new Float32Array(count * 3);
  const colors = new Float32Array(count * 3);
  const accent = new THREE.Color(theme.glow);
  const white = new THREE.Color(0xffffff);
  for (let index = 0; index < count; index += 1) {
    const theta = random() * Math.PI * 2;
    const z = random() * 2 - 1;
    const radius = Math.sqrt(1 - z * z);
    const speed = 1.25 + random() * 3.4;
    velocities[index * 3] = Math.cos(theta) * radius * speed;
    velocities[index * 3 + 1] = Math.sin(theta) * radius * speed;
    velocities[index * 3 + 2] = z * speed * .55;
    const color = accent.clone().lerp(white, random() * .75);
    colors[index * 3] = color.r;
    colors[index * 3 + 1] = color.g;
    colors[index * 3 + 2] = color.b;
  }
  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
  geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
  const material = new THREE.PointsMaterial({ size: .14, transparent: true, opacity: 0, vertexColors: true, map: particleTexture, alphaTest: .015, depthWrite: false, blending: THREE.AdditiveBlending });
  const points = new THREE.Points(geometry, material);
  points.position.set(0, .8, 2);
  points.visible = false;
  return { points, velocities, elapsed: 0 };
}

function mount(shell, options = {}) {
  if (!(shell instanceof HTMLElement) || !window.WebGLRenderingContext) return null;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const profile = qualityProfile();
  const templateId = Object.prototype.hasOwnProperty.call(THEMES, options.templateId) ? options.templateId : 'cosmic';
  const theme = THEMES[templateId];
  const random = randomFrom(seedFrom(options.seed));
  let renderer;
  try {
    renderer = new THREE.WebGLRenderer({ antialias: profile.dpr > 1, alpha: false, powerPreference: 'high-performance' });
  } catch (_error) {
    shell.classList.add('scene-fallback');
    return null;
  }
  renderer.setClearColor(theme.space, 1);
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, profile.dpr));
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  const canvas = renderer.domElement;
  canvas.className = 'universe-space-canvas';
  canvas.setAttribute('aria-hidden', 'true');
  shell.prepend(canvas);

  const scene = new THREE.Scene();
  scene.fog = new THREE.FogExp2(theme.space, .018);
  const camera = new THREE.PerspectiveCamera(48, 1, .1, 150);
  camera.position.set(0, 0, 10);
  const particleTexture = radialTexture();
  const stars = createStarField(profile.stars, theme, random, particleTexture);
  scene.add(stars);
  scene.add(new THREE.HemisphereLight(0xb8d9ff, 0x111017, 1.2));
  const keyLight = new THREE.DirectionalLight(0xffffff, 3.1);
  keyLight.position.set(-4, 3, 5);
  scene.add(keyLight);
  const rimLight = new THREE.DirectionalLight(theme.glow, 1.7);
  rimLight.position.set(4, -1, 2);
  scene.add(rimLight);

  let needsRender = true;
  const requestRender = () => { needsRender = true; };
  const moon = createMoon(profile, theme, requestRender);
  scene.add(moon.group);
  const moonAnchor = shell.querySelector('.moon-orbit-anchor');
  const satellite = createSatellite();
  scene.add(satellite);
  const comet = createComet(theme);
  scene.add(comet);
  const burst = createBurst(profile.burst, theme, random, particleTexture);
  scene.add(burst.points);

  let width = 1;
  let height = 1;
  let frame = 0;
  let lastTime = performance.now();
  let pointerX = 0;
  let pointerY = 0;
  let disposed = false;

  const resize = () => {
    width = Math.max(1, window.innerWidth);
    height = Math.max(1, window.innerHeight);
    renderer.setSize(width, height, false);
    camera.aspect = width / height;
    camera.updateProjectionMatrix();
    needsRender = true;
  };

  const positionMoon = () => {
    if (!(moonAnchor instanceof HTMLElement)) {
      moon.group.visible = false;
      return;
    }
    const rect = moonAnchor.getBoundingClientRect();
    moon.group.visible = rect.bottom > -80 && rect.top < height + 80;
    if (!moon.group.visible) return;
    const distance = camera.position.z;
    const worldHeight = 2 * Math.tan(THREE.MathUtils.degToRad(camera.fov / 2)) * distance;
    const worldWidth = worldHeight * camera.aspect;
    const centerX = rect.left + rect.width / 2;
    const centerY = rect.top + rect.height / 2;
    moon.group.position.x = ((centerX / width) * 2 - 1) * worldWidth / 2;
    moon.group.position.y = -((centerY / height) * 2 - 1) * worldHeight / 2;
    moon.group.position.z = 0;
    const scale = clamp((rect.width / width) * worldWidth / 2, .8, 2.15);
    moon.group.scale.setScalar(scale);
  };

  const render = () => {
    positionMoon();
    renderer.render(scene, camera);
    needsRender = false;
  };

  const animate = now => {
    if (disposed) return;
    const delta = clamp((now - lastTime) / 1000, 0, .05);
    lastTime = now;
    if (!document.hidden && !reducedMotion) {
      const elapsed = now / 1000;
      stars.rotation.y += delta * .007;
      stars.rotation.x = Math.sin(elapsed * .045) * .035;
      stars.material.opacity = .82 + Math.sin(elapsed * 1.35) * .12;
      moon.moon.rotation.y += delta * .055;
      moon.moon.rotation.x = Math.sin(elapsed * .11) * .05;
      camera.position.x += (pointerX * .18 - camera.position.x) * .025;
      camera.position.y += (-pointerY * .12 - camera.position.y) * .025;

      const cometCycle = (elapsed + (seedFrom(options.seed) % 11)) % 13;
      comet.visible = cometCycle < 2.8;
      if (comet.visible) {
        const progress = cometCycle / 2.8;
        comet.position.set(-7 + progress * 14, 3.4 - progress * 5.8, 2.4);
        comet.rotation.z = -.39;
      }
      satellite.position.set(Math.sin(elapsed * .105) * 5.4, 2.4 + Math.cos(elapsed * .13) * 1.5, -1.8);
      satellite.rotation.set(elapsed * .09, elapsed * .18, Math.sin(elapsed * .12) * .35);

      if (burst.points.visible) {
        burst.elapsed += delta;
        const positions = burst.points.geometry.attributes.position.array;
        for (let index = 0; index < profile.burst; index += 1) {
          positions[index * 3] += burst.velocities[index * 3] * delta;
          positions[index * 3 + 1] += burst.velocities[index * 3 + 1] * delta;
          positions[index * 3 + 2] += burst.velocities[index * 3 + 2] * delta;
          burst.velocities[index * 3 + 1] -= .16 * delta;
        }
        burst.points.geometry.attributes.position.needsUpdate = true;
        burst.points.material.opacity = clamp(1 - Math.max(0, burst.elapsed - 1.2) / 2.1, 0, .95);
        if (burst.elapsed > 3.3) burst.points.visible = false;
      }
      render();
    } else if (needsRender) {
      render();
    }
    frame = window.requestAnimationFrame(animate);
  };

  const triggerCelebration = () => {
    if (reducedMotion) return;
    const positions = burst.points.geometry.attributes.position.array;
    positions.fill(0);
    burst.points.geometry.attributes.position.needsUpdate = true;
    burst.points.material.opacity = .95;
    burst.points.visible = true;
    burst.elapsed = 0;
  };

  const onPointer = event => {
    pointerX = (event.clientX / Math.max(1, width)) * 2 - 1;
    pointerY = (event.clientY / Math.max(1, height)) * 2 - 1;
  };
  const onScroll = () => { needsRender = true; if (reducedMotion) render(); };
  const onVisibility = () => { lastTime = performance.now(); needsRender = true; };
  window.addEventListener('resize', resize, { passive: true });
  window.addEventListener('pointermove', onPointer, { passive: true });
  window.addEventListener('scroll', onScroll, { passive: true });
  document.addEventListener('visibilitychange', onVisibility);
  resize();
  positionMoon();
  render();
  frame = window.requestAnimationFrame(animate);

  return {
    triggerCelebration,
    destroy() {
      disposed = true;
      window.cancelAnimationFrame(frame);
      window.removeEventListener('resize', resize);
      window.removeEventListener('pointermove', onPointer);
      window.removeEventListener('scroll', onScroll);
      document.removeEventListener('visibilitychange', onVisibility);
      const textures = new Set();
      scene.traverse(object => {
        object.geometry?.dispose?.();
        const materials = Array.isArray(object.material) ? object.material : [object.material];
        materials.filter(Boolean).forEach(material => {
          ['map', 'bumpMap', 'alphaMap', 'emissiveMap'].forEach(key => {
            if (material[key]?.isTexture) textures.add(material[key]);
          });
          material.dispose?.();
        });
      });
      textures.forEach(texture => texture.dispose());
      renderer.dispose();
      canvas.remove();
    }
  };
}

window.BirthdayScene = Object.freeze({ mount });
window.dispatchEvent(new CustomEvent('birthday-scene-ready'));
