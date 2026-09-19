(() => {
  'use strict';

  const element = (tag, className = '', text = '') => {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== '') node.textContent = String(text);
    return node;
  };

  function locale(data) {
    return String(data?.locale || '').toLowerCase() === 'bn' ? 'bn-BD' : 'en-US';
  }

  function dateLabel(data) {
    const day = Number(data?.birthday_day || 1);
    const month = Number(data?.birthday_month || 1);
    const year = Number(data?.birthday_year || 0) || 2000;
    try {
      return new Intl.DateTimeFormat(locale(data), {
        day: 'numeric', month: 'long', ...(Number(data?.birthday_year || 0) ? { year: 'numeric' } : {})
      }).format(new Date(Date.UTC(year, month - 1, day)));
    } catch (_error) {
      return `${day}/${month}`;
    }
  }

  function copy(data, en, bn) {
    return String(data?.locale || '').toLowerCase() === 'bn' ? bn : en;
  }

  function addText(parent, tag, className, value) {
    const node = element(tag, className);
    node.textContent = String(value || '');
    parent.append(node);
    return node;
  }

  function audioContext() {
    const AudioContext = window.AudioContext || window.webkitAudioContext;
    return AudioContext ? new AudioContext() : null;
  }

  function ambientController() {
    let context = null;
    let master = null;
    let chimeTimer = 0;
    let initialized = false;

    const chime = () => {
      if (!context || context.state !== 'running' || !master) return;
      const now = context.currentTime;
      const oscillator = context.createOscillator();
      const gain = context.createGain();
      oscillator.type = 'sine';
      oscillator.frequency.setValueAtTime(440 + Math.random() * 260, now);
      oscillator.frequency.exponentialRampToValueAtTime(220 + Math.random() * 120, now + 2.8);
      gain.gain.setValueAtTime(0.0001, now);
      gain.gain.exponentialRampToValueAtTime(.045, now + .08);
      gain.gain.exponentialRampToValueAtTime(.0001, now + 3.1);
      oscillator.connect(gain).connect(master);
      oscillator.start(now);
      oscillator.stop(now + 3.2);
    };

    const initialize = () => {
      if (initialized) return;
      context = audioContext();
      if (!context) throw new Error('Audio is not supported in this browser.');
      master = context.createGain();
      master.gain.value = .115;
      master.connect(context.destination);
      [55, 82.4, 123.5].forEach((frequency, index) => {
        const oscillator = context.createOscillator();
        const gain = context.createGain();
        const filter = context.createBiquadFilter();
        oscillator.type = index === 2 ? 'sine' : 'triangle';
        oscillator.frequency.value = frequency;
        oscillator.detune.value = index * 4 - 3;
        filter.type = 'lowpass';
        filter.frequency.value = 420 + index * 170;
        gain.gain.value = index === 0 ? .18 : .08;
        oscillator.connect(filter).connect(gain).connect(master);
        oscillator.start();
      });
      const noiseBuffer = context.createBuffer(1, context.sampleRate * 2, context.sampleRate);
      const samples = noiseBuffer.getChannelData(0);
      for (let index = 0; index < samples.length; index += 1) samples[index] = (Math.random() * 2 - 1) * .22;
      const noise = context.createBufferSource();
      const noiseFilter = context.createBiquadFilter();
      const noiseGain = context.createGain();
      noise.buffer = noiseBuffer;
      noise.loop = true;
      noiseFilter.type = 'lowpass';
      noiseFilter.frequency.value = 310;
      noiseGain.gain.value = .055;
      noise.connect(noiseFilter).connect(noiseGain).connect(master);
      noise.start();
      initialized = true;
    };

    return {
      get paused() { return !context || context.state !== 'running'; },
      async play() {
        initialize();
        await context.resume();
        chime();
        window.clearInterval(chimeTimer);
        chimeTimer = window.setInterval(chime, 6500);
      },
      async pause() {
        window.clearInterval(chimeTimer);
        if (context?.state === 'running') await context.suspend();
      },
      destroy() {
        window.clearInterval(chimeTimer);
        if (context && context.state !== 'closed') void context.close();
      }
    };
  }

  function mediaController(url) {
    const audio = new Audio(String(url || ''));
    audio.preload = 'metadata';
    return {
      get paused() { return audio.paused; },
      play: () => audio.play(),
      pause: async () => { audio.pause(); },
      onEnded: callback => audio.addEventListener('ended', callback),
      destroy() { audio.pause(); audio.removeAttribute('src'); audio.load(); }
    };
  }

  function bufferController(loader) {
    let context = null;
    let buffer = null;
    let source = null;
    let startedAt = 0;
    let offset = 0;
    let playing = false;
    let endedCallback = () => {};
    const stop = ended => {
      if (!source) return;
      source.onended = null;
      try { source.stop(); } catch (_error) {}
      source.disconnect();
      source = null;
      if (ended) {
        offset = 0;
        playing = false;
        endedCallback();
      }
    };
    return {
      get paused() { return !playing; },
      async play() {
        context = context || audioContext();
        if (!context) throw new Error('Audio is not supported in this browser.');
        await context.resume();
        if (!buffer) {
          const bytes = await loader();
          buffer = await context.decodeAudioData(bytes.slice(0));
        }
        source = context.createBufferSource();
        source.buffer = buffer;
        source.connect(context.destination);
        source.onended = () => {
          if (!playing) return;
          source = null;
          offset = 0;
          playing = false;
          endedCallback();
        };
        startedAt = context.currentTime;
        source.start(0, offset % buffer.duration);
        playing = true;
      },
      async pause() {
        if (!playing || !context) return;
        offset += context.currentTime - startedAt;
        playing = false;
        stop(false);
      },
      onEnded(callback) { endedCallback = callback; },
      destroy() { playing = false; stop(false); if (context && context.state !== 'closed') void context.close(); }
    };
  }

  function soundtrackController(universe, options) {
    const soundtrack = universe.soundtrack && typeof universe.soundtrack === 'object'
      ? universe.soundtrack
      : (universe.music?.url ? { ...universe.music, mode: 'PLATFORM' } : { mode: 'NONE' });
    const mode = String(soundtrack.mode || 'NONE').toUpperCase();
    if (mode === 'NONE') return { mode, soundtrack, controller: null };
    if (mode === 'AMBIENT') return { mode, soundtrack, controller: ambientController() };
    if (mode === 'CUSTOM' && typeof options.loadSoundtrack === 'function') {
      return { mode, soundtrack, controller: bufferController(options.loadSoundtrack) };
    }
    return soundtrack.url
      ? { mode, soundtrack, controller: mediaController(soundtrack.url) }
      : { mode: 'NONE', soundtrack, controller: null };
  }

  function mountScene(shell, universe, templateId) {
    let scene = null;
    let fallbackTimer = 0;
    const mount = () => {
      if (scene || !window.BirthdayScene?.mount) return;
      scene = window.BirthdayScene.mount(shell, {
        templateId,
        seed: `${universe.star_id || 'preview'}|${universe.name || 'birthday'}`
      });
      if (scene) shell.classList.remove('scene-fallback');
    };
    mount();
    if (!scene) window.addEventListener('birthday-scene-ready', mount, { once: true });
    fallbackTimer = window.setTimeout(() => {
      if (!scene) shell.classList.add('scene-fallback');
    }, 1500);
    return {
      celebrate() { scene?.triggerCelebration?.(); },
      destroy() { window.clearTimeout(fallbackTimer); window.removeEventListener('birthday-scene-ready', mount); scene?.destroy?.(); }
    };
  }

  function render(root, universe, options = {}) {
    if (!(root instanceof HTMLElement)) return;
    root._birthdayDestroy?.();
    root.replaceChildren();
    const template = universe?.template && typeof universe.template === 'object' ? universe.template : { id: 'cosmic', configuration: {} };
    const templateId = ['cosmic', 'dreamy', 'celebration'].includes(String(template.id)) ? String(template.id) : 'cosmic';
    const shell = element('article', `birthday-universe template-${templateId} awaiting-entry`);
    shell.style.setProperty('--template-accent', /^#[a-f0-9]{6}$/i.test(template.configuration?.accent || '') ? template.configuration.accent : '#63e6be');
    shell.style.setProperty('--template-secondary', /^#[a-f0-9]{6}$/i.test(template.configuration?.secondary || '') ? template.configuration.secondary : '#f6c86b');
    const atmosphere = element('div', 'universe-atmosphere');
    atmosphere.setAttribute('aria-hidden', 'true');
    shell.append(atmosphere);

    const opening = element('section', 'universe-opening');
    opening.tabIndex = -1;
    const openingInner = element('div', 'opening-story');
    addText(openingInner, 'p', 'welcome opening-beat beat-one', copy(universe, 'Welcome to', 'স্বাগতম'));
    const title = element('h1', 'opening-beat beat-two');
    addText(title, 'span', '', universe.name || 'Birthday');
    title.append(document.createTextNode(copy(universe, "'S BIRTHDAY UNIVERSE", '-এর জন্মদিনের মহাবিশ্ব')));
    openingInner.append(title);
    addText(openingInner, 'p', 'birthday-date opening-beat beat-three', dateLabel(universe));
    addText(openingInner, 'p', 'opening-wish opening-beat beat-four', copy(universe, `Happy Birthday, ${universe.name}!`, `শুভ জন্মদিন, ${universe.name}!`));
    opening.append(openingInner);
    shell.append(opening);

    const star = element('section', 'star-reveal');
    const starInner = element('div');
    addText(starInner, 'div', 'star-core', '✦');
    addText(starInner, 'p', 'eyebrow', copy(universe, 'A light made personal', 'একটি ব্যক্তিগত আলো'));
    addText(starInner, 'h2', '', copy(universe, `${universe.name}'s Personal Star`, `${universe.name}-এর ব্যক্তিগত তারা`));
    addText(starInner, 'div', 'star-id', universe.star_id || 'PREVIEW-STAR');
    addText(starInner, 'p', '', copy(universe, `A personalized digital star created especially for ${universe.name}.`, `${universe.name}-এর জন্য তৈরি একটি ব্যক্তিগত ডিজিটাল তারা।`));
    addText(starInner, 'p', 'star-disclaimer', copy(universe, 'This is a fictional digital element created within this website and does not represent ownership of a real astronomical object.', 'এটি এই ওয়েবসাইটের একটি কাল্পনিক ডিজিটাল উপাদান; কোনো বাস্তব মহাজাগতিক বস্তুর মালিকানা নির্দেশ করে না।'));
    star.append(starInner);
    shell.append(star);

    const moon = element('section', 'moon-section');
    const moonInner = element('div');
    const moonAnchor = element('div', 'moon-orbit-anchor');
    moonAnchor.setAttribute('role', 'img');
    moonAnchor.setAttribute('aria-label', copy(universe, 'A slowly rotating decorative moon', 'ধীরে ঘূর্ণায়মান অলংকারিক চাঁদ'));
    moonInner.append(moonAnchor);
    addText(moonInner, 'p', 'eyebrow', dateLabel(universe));
    addText(moonInner, 'h2', '', copy(universe, `${universe.name}'s Birthday Moon`, `${universe.name}-এর জন্মদিনের চাঁদ`));
    addText(moonInner, 'p', '', copy(universe, 'A photorealistic decorative moon created for this birthday story. It is not an astronomical moon-phase record.', 'এই জন্মদিনের গল্পের জন্য তৈরি বাস্তবসম্মত অলংকারিক চাঁদ; এটি কোনো জ্যোতির্বৈজ্ঞানিক চাঁদের দশার রেকর্ড নয়।'));
    moon.append(moonInner);
    shell.append(moon);

    if (String(universe.message || '').trim() || String(universe.sender_name || '').trim()) {
      const messageSection = element('section', 'message-section');
      const card = element('div', 'message-card');
      addText(card, 'p', 'eyebrow', copy(universe, 'A message is waiting', 'একটি বার্তা অপেক্ষায় আছে'));
      addText(card, 'h2', '', copy(universe, 'Open Your Birthday Message', 'জন্মদিনের বার্তা খুলুন'));
      const toggle = element('button', 'button primary message-toggle', copy(universe, 'Open Message', 'বার্তা খুলুন'));
      toggle.type = 'button';
      const message = element('div', 'birthday-message');
      message.hidden = true;
      message.textContent = String(universe.message || copy(universe, `Happy Birthday, ${universe.name}!`, `শুভ জন্মদিন, ${universe.name}!`));
      const signature = element('div', 'message-signature');
      if (String(universe.sender_name || '').trim()) signature.textContent = copy(universe, `With love, ${universe.sender_name}`, `ভালোবাসায়, ${universe.sender_name}`);
      toggle.addEventListener('click', () => {
        message.hidden = false;
        signature.hidden = false;
        toggle.hidden = true;
        message.focus({ preventScroll: true });
      });
      message.tabIndex = -1;
      signature.hidden = true;
      card.append(toggle, message, signature);
      messageSection.append(card);
      shell.append(messageSection);
    }

    if (String(universe.photo_url || '').trim()) {
      const photoSection = element('section', 'photo-section');
      const photoInner = element('div');
      addText(photoInner, 'p', 'eyebrow', copy(universe, 'A moment to keep', 'স্মৃতিতে রাখার একটি মুহূর্ত'));
      addText(photoInner, 'h2', '', copy(universe, 'Photo Memory', 'ছবির স্মৃতি'));
      const frame = element('figure', 'photo-frame');
      const image = new Image();
      image.alt = copy(universe, `A birthday memory for ${universe.name}`, `${universe.name}-এর জন্মদিনের স্মৃতি`);
      image.loading = options.preview ? 'eager' : 'lazy';
      image.decoding = 'async';
      if (Number(universe.photo_width) > 0) image.width = Number(universe.photo_width);
      if (Number(universe.photo_height) > 0) image.height = Number(universe.photo_height);
      const photoUrl = String(universe.photo_url);
      let photoRetries = 0;
      image.src = photoUrl;
      image.addEventListener('error', () => {
        if (photoRetries < 1 && !options.preview) {
          photoRetries += 1;
          window.setTimeout(() => {
            const separator = photoUrl.includes('?') ? '&' : '?';
            image.src = `${photoUrl}${separator}retry=1`;
          }, 500);
          return;
        }
        photoSection.remove();
      });
      frame.append(image);
      photoInner.append(frame);
      photoSection.append(photoInner);
      shell.append(photoSection);
    }

    const finale = element('section', 'universe-finale');
    addText(finale, 'p', 'eyebrow', universe.star_id || '');
    addText(finale, 'h2', '', copy(universe, `Happy Birthday, ${universe.name}!`, `শুভ জন্মদিন, ${universe.name}!`));
    addText(finale, 'p', '', copy(universe, 'May your next orbit be full of light.', 'তোমার আগামী পথ আলোয় ভরে উঠুক।'));
    shell.append(finale);

    const sound = soundtrackController(universe, options);
    let playedEvent = false;
    let musicButton = null;
    const updateMusic = () => {
      if (!musicButton || !sound.controller) return;
      musicButton.replaceChildren(
        element('span', 'music-control-icon', sound.controller.paused ? '♪' : 'Ⅱ'),
        element('span', '', sound.controller.paused ? copy(universe, 'Play sound', 'Sound চালান') : copy(universe, 'Pause sound', 'Sound থামান'))
      );
      musicButton.setAttribute('aria-pressed', String(!sound.controller.paused));
    };
    const playSound = async () => {
      if (!sound.controller) return;
      await sound.controller.play();
      if (!playedEvent) {
        playedEvent = true;
        options.onMusicPlay?.(sound.soundtrack.id || sound.mode);
      }
      updateMusic();
    };
    if (sound.controller) {
      musicButton = element('button', 'music-control');
      musicButton.type = 'button';
      musicButton.title = copy(universe, 'Play or pause birthday sound', 'Birthday sound চালু বা বন্ধ করুন');
      musicButton.addEventListener('click', async () => {
        try {
          if (sound.controller.paused) await playSound();
          else await sound.controller.pause();
          updateMusic();
        } catch (_error) {
          musicButton.replaceChildren(element('span', 'music-control-icon', '!'), element('span', '', copy(universe, 'Sound unavailable', 'Sound চালানো যায়নি')));
        }
      });
      sound.controller.onEnded?.(updateMusic);
      updateMusic();
      shell.append(musicButton);
    }

    const entry = element('div', 'universe-entry');
    entry.setAttribute('role', 'dialog');
    entry.setAttribute('aria-modal', 'true');
    entry.setAttribute('aria-label', copy(universe, 'Enter Birthday Universe', 'Birthday Universe-এ প্রবেশ করুন'));
    const entryContent = element('div', 'universe-entry-content');
    addText(entryContent, 'p', 'eyebrow', copy(universe, `A universe for ${universe.name}`, `${universe.name}-এর জন্য একটি মহাবিশ্ব`));
    addText(entryContent, 'h2', '', copy(universe, 'A birthday universe is waiting', 'একটি Birthday Universe অপেক্ষায় আছে'));
    const enterButton = element('button', 'button primary universe-enter-button', copy(universe, 'Enter Birthday Universe', 'Birthday Universe-এ প্রবেশ করুন'));
    enterButton.type = 'button';
    entryContent.append(enterButton);
    addText(entryContent, 'p', 'entry-sound-note', sound.controller ? copy(universe, 'Tap once to begin the celebration and sound.', 'উদযাপন ও sound শুরু করতে একবার tap করুন।') : copy(universe, 'Tap once to begin the celebration.', 'উদযাপন শুরু করতে একবার tap করুন।'));
    entry.append(entryContent);
    shell.append(entry);
    root.append(shell);

    const scene = mountScene(shell, universe, templateId);
    enterButton.focus({ preventScroll: true });
    enterButton.addEventListener('click', () => {
      enterButton.disabled = true;
      shell.classList.remove('awaiting-entry');
      shell.classList.add('universe-entered');
      entry.classList.add('leaving');
      scene.celebrate();
      void playSound().catch(() => {
        if (musicButton) musicButton.replaceChildren(element('span', 'music-control-icon', '!'), element('span', '', copy(universe, 'Sound unavailable', 'Sound চালানো যায়নি')));
      });
      window.setTimeout(() => {
        entry.remove();
        opening.focus({ preventScroll: true });
      }, 700);
    }, { once: true });

    root._birthdayDestroy = () => {
      scene.destroy();
      sound.controller?.destroy?.();
      root._birthdayDestroy = null;
    };
  }

  window.BirthdayTemplates = Object.freeze({ render, dateLabel });
})();
