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
      const value = new Intl.DateTimeFormat(locale(data), {
        day: 'numeric', month: 'long', ...(Number(data?.birthday_year || 0) ? { year: 'numeric' } : {})
      }).format(new Date(Date.UTC(year, month - 1, day)));
      return value;
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

  function render(root, universe, options = {}) {
    if (!(root instanceof HTMLElement)) return;
    root.replaceChildren();
    const template = universe?.template && typeof universe.template === 'object' ? universe.template : { id: 'cosmic', configuration: {} };
    const templateId = ['cosmic', 'dreamy', 'celebration'].includes(String(template.id)) ? String(template.id) : 'cosmic';
    const shell = element('article', `birthday-universe template-${templateId}`);
    shell.style.setProperty('--template-accent', /^#[a-f0-9]{6}$/i.test(template.configuration?.accent || '') ? template.configuration.accent : '#63e6be');
    shell.style.setProperty('--template-secondary', /^#[a-f0-9]{6}$/i.test(template.configuration?.secondary || '') ? template.configuration.secondary : '#f6c86b');

    const opening = element('section', 'universe-opening');
    const openingInner = element('div');
    addText(openingInner, 'p', 'welcome', copy(universe, 'Welcome to', 'স্বাগতম'));
    const title = element('h1');
    addText(title, 'span', '', universe.name || 'Birthday');
    title.append(document.createTextNode(copy(universe, "'S BIRTHDAY UNIVERSE", '-এর জন্মদিনের মহাবিশ্ব')));
    openingInner.append(title);
    addText(openingInner, 'p', 'birthday-date', dateLabel(universe));
    opening.append(openingInner);
    shell.append(opening);

    const star = element('section', 'star-reveal');
    const starInner = element('div');
    addText(starInner, 'div', 'star-core', '✦');
    addText(starInner, 'p', 'eyebrow', copy(universe, 'A light made personal', 'একটি ব্যক্তিগত আলো'));
    addText(starInner, 'h2', '', copy(universe, `${universe.name}'s Personal Star`, `${universe.name}-এর ব্যক্তিগত তারা`));
    addText(starInner, 'div', 'star-id', universe.star_id || 'PREVIEW-STAR');
    addText(starInner, 'p', '', copy(universe, `A personalized digital star created especially for ${universe.name}.`, `${universe.name}-এর জন্য তৈরি একটি ব্যক্তিগত ডিজিটাল তারা।`));
    addText(starInner, 'p', 'star-disclaimer', copy(
      universe,
      'This is a fictional digital element created within this website and does not represent ownership of a real astronomical object.',
      'এটি এই ওয়েবসাইটের একটি কাল্পনিক ডিজিটাল উপাদান; কোনো বাস্তব মহাজাগতিক বস্তুর মালিকানা নির্দেশ করে না।'
    ));
    star.append(starInner);
    shell.append(star);

    const moon = element('section', 'moon-section');
    const moonInner = element('div');
    addText(moonInner, 'div', 'moon-visual', '◐');
    addText(moonInner, 'p', 'eyebrow', dateLabel(universe));
    addText(moonInner, 'h2', '', copy(universe, `${universe.name}'s Birthday Moon`, `${universe.name}-এর জন্মদিনের চাঁদ`));
    addText(moonInner, 'p', '', copy(
      universe,
      'A decorative moon created for this personal birthday story. It is not an astronomical moon-phase record.',
      'এই ব্যক্তিগত জন্মদিনের গল্পের জন্য তৈরি একটি অলংকারিক চাঁদ; এটি কোনো জ্যোতির্বৈজ্ঞানিক চাঁদের দশার রেকর্ড নয়।'
    ));
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
      const frame = element('div', 'photo-frame');
      const image = new Image();
      image.alt = copy(universe, `A birthday memory for ${universe.name}`, `${universe.name}-এর জন্মদিনের স্মৃতি`);
      image.loading = options.preview ? 'eager' : 'lazy';
      image.decoding = 'async';
      image.src = String(universe.photo_url);
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

    if (universe.music?.url) {
      const audio = new Audio(String(universe.music.url));
      audio.preload = 'none';
      const musicButton = element('button', 'music-control');
      musicButton.type = 'button';
      const update = () => { musicButton.textContent = audio.paused ? `♪ ${copy(universe, 'Play Music', 'গান চালান')}` : `Ⅱ ${copy(universe, 'Pause Music', 'গান থামান')}`; };
      update();
      musicButton.addEventListener('click', async () => {
        if (audio.paused) {
          try {
            await audio.play();
            options.onMusicPlay?.(universe.music.id || '');
          } catch (_error) {
            musicButton.textContent = copy(universe, 'Music could not play', 'গান চালানো যায়নি');
          }
        } else {
          audio.pause();
        }
        update();
      });
      audio.addEventListener('ended', update);
      shell.append(musicButton);
    }

    root.append(shell);
  }

  window.BirthdayTemplates = Object.freeze({ render, dateLabel });
})();
