(() => {
  'use strict';

  const api = window.BirthdayApi;
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
  const page = document.body.dataset.page || 'home';
  const stateKey = 'birthday_create_state_v1';
  const localeKey = 'birthday_locale_v1';

  const copy = {
    en: {
      navTemplates:'Templates',navZsky:'Z Sky 24',heroEyebrow:'A free digital birthday experience',heroTitle:'Create Your Birthday Universe',heroLead:'Create a beautiful personalized birthday universe in minutes. It is free.',createCta:'Create My Universe',exploreCta:'Explore Templates',heroTrust:'No account is needed to create and share. Z-Pay login is required only to edit or delete later.',experienceEyebrow:'One link, a whole celebration',experienceTitle:'A story made for one special person',featureStar:'Personal Star',featureStarText:'A fictional digital star identifier made for the birthday page.',featureMoon:'Birthday Moon',featureMoonText:'A personalized moon scene built around the birthday date.',featureMessage:'Hidden Message',featureMessageText:'An elegant message reveal for your birthday wish.',featureMemory:'Photo Memory',featureMemoryText:'Add one optional photo in an optimized keepsake frame.',featureMusic:'Music',featureMusicText:'Choose a licensed track and let the recipient press play.',howEyebrow:'Ready in minutes',howTitle:'You create it. The platform does the rest.',howOne:'Add the birthday details',howOneText:'Write the name, date and message.',howTwo:'Choose the atmosphere',howTwoText:'Pick a template, photo and optional music.',howThree:'Generate and share',howThreeText:'Receive a unique link and QR code automatically.',startNow:'Start Creating',creatorEyebrow:'Create for free',creatorTitle:'Build a Birthday Universe',stepNameTitle:'Who are we celebrating?',stepNameText:"Enter the birthday person's name.",nameLabel:'Birthday person name',stepDateTitle:'When is the birthday?',stepDateText:'Day and month are required. Year is optional.',dayLabel:'Day',monthLabel:'Month',yearLabel:'Year (optional)',stepSenderTitle:'Add your name',stepSenderText:'This is optional and appears as the sender.',senderLabel:'Sender name',stepMessageTitle:'Write the birthday message',stepMessageText:'The recipient will open this message inside the Universe.',messageLabel:'Birthday message',stepPhotoTitle:'Add a photo',stepPhotoText:'Optional. JPEG, PNG or WebP, up to 5 MB.',photoChoose:'Choose photo',photoRemove:'Remove photo',stepTemplateTitle:'Choose a Universe',stepTemplateText:'Each style uses the same birthday details in a different atmosphere.',stepMusicTitle:'Choose the finishing touch',stepMusicText:'Music never autoplays. The recipient stays in control.',visibilityTitle:'Search visibility',unlistedTitle:'Unlisted',unlistedText:'Only people with the link can open it.',publicTitle:'Public',publicText:'Search engines may index the page.',sharePhotoLabel:'Use the uploaded photo in social link previews.',consentLabel:'I have permission to publish this name, message and photo.',backButton:'Back',continueButton:'Continue',previewEyebrow:'Private preview',previewTitle:'Your Universe is almost ready',loadingPreview:'Loading your preview…',generateTitle:'Ready to make it real?',generateText:'Generation is automatic. Ads never block access to your birthday page.',generateButton:'Generate Universe',successEyebrow:'Universe created',successTitle:'Your shareable link is ready',recoveryTitle:'Save this recovery code',recoveryText:'You will need this code plus Z-Pay login to edit or delete the Universe.',copyRecovery:'Copy recovery code',openUniverse:'Open Universe',shareButton:'Share',templatesEyebrow:'Three ways to celebrate',templatesTitle:'Choose the feeling of the moment',templatesLead:'Every template is responsive, accessible and built for a personal birthday story.',manageEyebrow:'Owner controls',manageTitle:'Manage Birthday Universe',manageLoading:'Checking your Z-Pay access…'
    },
    bn: {
      navTemplates:'টেমপ্লেট',navZsky:'Z Sky 24',heroEyebrow:'একটি ফ্রি ডিজিটাল জন্মদিনের অভিজ্ঞতা',heroTitle:'আপনার Birthday Universe তৈরি করুন',heroLead:'কয়েক মিনিটে সুন্দর ব্যক্তিগত জন্মদিনের মহাবিশ্ব তৈরি করুন। সম্পূর্ণ ফ্রি।',createCta:'আমার Universe তৈরি করুন',exploreCta:'টেমপ্লেট দেখুন',heroTrust:'তৈরি ও শেয়ার করতে account লাগবে না। পরে edit বা delete করতে শুধু Z-Pay login লাগবে।',experienceEyebrow:'একটি লিংক, সম্পূর্ণ আয়োজন',experienceTitle:'একজন বিশেষ মানুষের জন্য তৈরি গল্প',featureStar:'ব্যক্তিগত তারা',featureStarText:'জন্মদিনের পেজের জন্য তৈরি একটি কাল্পনিক ডিজিটাল তারার পরিচয়।',featureMoon:'জন্মদিনের চাঁদ',featureMoonText:'জন্মতারিখ ঘিরে তৈরি ব্যক্তিগত চাঁদের দৃশ্য।',featureMessage:'গোপন বার্তা',featureMessageText:'আপনার শুভেচ্ছা সুন্দরভাবে খুলে দেখার আয়োজন।',featureMemory:'ছবির স্মৃতি',featureMemoryText:'একটি ঐচ্ছিক ছবি সুন্দর স্মৃতি হিসেবে যোগ করুন।',featureMusic:'গান',featureMusicText:'অনুমোদিত গান বেছে দিন; প্রাপক নিজে Play করবে।',howEyebrow:'কয়েক মিনিটেই প্রস্তুত',howTitle:'আপনি তৈরি করবেন, বাকি কাজ প্ল্যাটফর্ম করবে।',howOne:'জন্মদিনের তথ্য দিন',howOneText:'নাম, তারিখ ও বার্তা লিখুন।',howTwo:'পরিবেশ বেছে নিন',howTwoText:'টেমপ্লেট, ছবি ও ঐচ্ছিক গান বেছে নিন।',howThree:'তৈরি করে শেয়ার করুন',howThreeText:'স্বয়ংক্রিয়ভাবে একটি unique link ও QR code পাবেন।',startNow:'এখনই শুরু করুন',creatorEyebrow:'ফ্রিতে তৈরি করুন',creatorTitle:'Birthday Universe বানান',stepNameTitle:'কার জন্মদিন উদযাপন করছেন?',stepNameText:'জন্মদিনের ব্যক্তির নাম লিখুন।',nameLabel:'জন্মদিনের ব্যক্তির নাম',stepDateTitle:'জন্মদিন কবে?',stepDateText:'দিন ও মাস আবশ্যক। সাল ঐচ্ছিক।',dayLabel:'দিন',monthLabel:'মাস',yearLabel:'সাল (ঐচ্ছিক)',stepSenderTitle:'আপনার নাম দিন',stepSenderText:'এটি ঐচ্ছিক এবং প্রেরকের নাম হিসেবে দেখাবে।',senderLabel:'প্রেরকের নাম',stepMessageTitle:'জন্মদিনের বার্তা লিখুন',stepMessageText:'প্রাপক Universe-এর ভিতরে বার্তাটি খুলবে।',messageLabel:'জন্মদিনের বার্তা',stepPhotoTitle:'একটি ছবি দিন',stepPhotoText:'ঐচ্ছিক। JPEG, PNG অথবা WebP, সর্বোচ্চ ৫ MB।',photoChoose:'ছবি বেছে নিন',photoRemove:'ছবি সরান',stepTemplateTitle:'একটি Universe বেছে নিন',stepTemplateText:'একই তথ্য প্রতিটি style-এ আলাদা অনুভূতি তৈরি করবে।',stepMusicTitle:'শেষ ছোঁয়া বেছে নিন',stepMusicText:'গান কখনো নিজে থেকে চলবে না। প্রাপক নিয়ন্ত্রণ করবে।',visibilityTitle:'Search visibility',unlistedTitle:'Unlisted',unlistedText:'শুধু লিংক থাকা মানুষ খুলতে পারবে।',publicTitle:'Public',publicText:'Search engine পেজটি index করতে পারবে।',sharePhotoLabel:'Social link preview-তে আপলোড করা ছবি ব্যবহার করুন।',consentLabel:'এই নাম, বার্তা ও ছবি প্রকাশের অনুমতি আমার আছে।',backButton:'পেছনে',continueButton:'এগিয়ে যান',previewEyebrow:'Private preview',previewTitle:'আপনার Universe প্রায় প্রস্তুত',loadingPreview:'Preview লোড হচ্ছে…',generateTitle:'এটি প্রকাশ করতে প্রস্তুত?',generateText:'Generation স্বয়ংক্রিয়। বিজ্ঞাপন কখনো আপনার birthday page আটকে রাখবে না।',generateButton:'Universe তৈরি করুন',successEyebrow:'Universe তৈরি হয়েছে',successTitle:'আপনার shareable link প্রস্তুত',recoveryTitle:'Recovery code সংরক্ষণ করুন',recoveryText:'পরে edit বা delete করতে এই code এবং Z-Pay login লাগবে।',copyRecovery:'Recovery code কপি করুন',openUniverse:'Universe খুলুন',shareButton:'শেয়ার',templatesEyebrow:'উদযাপনের তিনটি ধরন',templatesTitle:'মুহূর্তটির অনুভূতি বেছে নিন',templatesLead:'প্রতিটি template responsive, accessible এবং ব্যক্তিগত জন্মদিনের গল্পের জন্য তৈরি।',manageEyebrow:'Owner controls',manageTitle:'Birthday Universe পরিচালনা করুন',manageLoading:'Z-Pay access যাচাই হচ্ছে…'
    }
  };

  Object.assign(copy.en, {
    stepMusicText:'Sound begins after the recipient enters the Universe.',audioChoose:'Choose your audio',audioLimit:'MP3, M4A or OGG, up to 30 seconds.',audioRemove:'Remove audio',audioRightsLabel:'I have permission to use this audio on a public birthday page.',consentLabel:'I have permission to publish this name, message, photo and audio.',
    footerBrand:'Digital Birthday Universe · Z Sky 24',footerPrivacy:'Privacy',footerTerms:'Terms',footerContact:'Contact',footerDisclaimer:'This personalized star is a fictional digital element and does not represent ownership of a real astronomical object.',
    privacyEyebrow:'Privacy',privacyTitle:'Birthday Universe Privacy',privacyIntro:'Birthday Universe stores the details you submit so the shareable page can work. A birthday day and month are required; the year and photo are optional.',privacyRetentionTitle:'Visibility and retention',privacyRetentionText:'New pages are unlisted by default. They remain accessible to anyone who has the unique link, expire after 90 days by default, and may be renewed by a claimed owner.',privacyAnalyticsTitle:'Photos and analytics',privacyAnalyticsText:'Photos are validated, optimized and kept in private server storage. We record limited privacy-conscious events such as views, shares and QR downloads without publishing internal identifiers.',privacyControlsTitle:'Your controls',privacyControlsText:'After claiming a page with Z-Pay login and its recovery code, you can update, renew or delete it. You may also report a page from its public view.',
    termsEyebrow:'Terms',termsTitle:'Birthday Universe Terms',termsIntro:'You may publish only content you have permission to share. Do not upload unlawful, abusive, deceptive, infringing or private material without consent.',termsFictionTitle:'Fictional digital experience',termsFictionText:'Personal stars, moons and universe elements are creative digital features. They do not represent ownership, registration or legal rights to any real astronomical object.',termsAvailabilityTitle:'Availability',termsAvailabilityText:'Pages may expire, be removed after a valid report, or become unavailable during maintenance. Advertisements do not require or reward clicks.',
    contactEyebrow:'Contact',contactTitle:'Contact Z Sky 24',contactText:'For privacy, copyright or abuse concerns, use the Report action on the relevant Birthday Universe. For account support, open the Z-Pay support center.',contactSupport:'Open Z-Pay Support'
  });
  Object.assign(copy.bn, {
    stepMusicText:'প্রাপক Universe-এ প্রবেশ করার পর sound শুরু হবে।',audioChoose:'নিজের audio বেছে নিন',audioLimit:'MP3, M4A অথবা OGG, সর্বোচ্চ ৩০ সেকেন্ড।',audioRemove:'Audio সরান',audioRightsLabel:'Public birthday page-এ এই audio ব্যবহারের অনুমতি আমার আছে।',consentLabel:'এই নাম, বার্তা, ছবি ও audio প্রকাশের অনুমতি আমার আছে।',
    footerBrand:'Digital Birthday Universe · Z Sky 24',footerPrivacy:'গোপনীয়তা',footerTerms:'শর্তাবলি',footerContact:'যোগাযোগ',footerDisclaimer:'এই ব্যক্তিগত তারাটি একটি কাল্পনিক ডিজিটাল উপাদান; এটি কোনো বাস্তব মহাজাগতিক বস্তুর মালিকানা নির্দেশ করে না।',
    privacyEyebrow:'গোপনীয়তা',privacyTitle:'Birthday Universe গোপনীয়তা',privacyIntro:'Shareable page চালাতে Birthday Universe আপনার দেওয়া তথ্য সংরক্ষণ করে। জন্মদিনের দিন ও মাস আবশ্যক; সাল ও ছবি ঐচ্ছিক।',privacyRetentionTitle:'দৃশ্যমানতা ও সংরক্ষণ',privacyRetentionText:'নতুন page শুরুতে unlisted থাকে। Unique link থাকা যে কেউ এটি খুলতে পারে, default হিসেবে ৯০ দিন পরে মেয়াদ শেষ হয় এবং claimed owner এটি renew করতে পারেন।',privacyAnalyticsTitle:'ছবি ও analytics',privacyAnalyticsText:'ছবি যাচাই ও optimize করে private server storage-এ রাখা হয়। Internal identifier প্রকাশ না করে view, share ও QR download-এর মতো সীমিত privacy-conscious event রাখা হয়।',privacyControlsTitle:'আপনার নিয়ন্ত্রণ',privacyControlsText:'Z-Pay login ও recovery code দিয়ে page claim করার পর update, renew বা delete করতে পারবেন। Public view থেকে page report-ও করা যায়।',
    termsEyebrow:'শর্তাবলি',termsTitle:'Birthday Universe শর্তাবলি',termsIntro:'শুধু যে content শেয়ার করার অনুমতি আপনার আছে সেটিই প্রকাশ করুন। সম্মতি ছাড়া বেআইনি, আপত্তিকর, প্রতারণামূলক, অধিকার লঙ্ঘনকারী বা ব্যক্তিগত material upload করবেন না।',termsFictionTitle:'কাল্পনিক ডিজিটাল অভিজ্ঞতা',termsFictionText:'Personal star, moon ও universe element সৃজনশীল ডিজিটাল feature। এগুলো কোনো বাস্তব মহাজাগতিক বস্তুর মালিকানা, registration বা আইনি অধিকার নির্দেশ করে না।',termsAvailabilityTitle:'প্রাপ্যতা',termsAvailabilityText:'Page-এর মেয়াদ শেষ হতে পারে, valid report-এর পর সরানো হতে পারে অথবা maintenance-এর সময় সাময়িক unavailable হতে পারে। বিজ্ঞাপনে click করা আবশ্যক নয় এবং click-এর জন্য reward দেওয়া হয় না।',
    contactEyebrow:'যোগাযোগ',contactTitle:'Z Sky 24-এর সঙ্গে যোগাযোগ',contactText:'গোপনীয়তা, copyright বা abuse বিষয়ে সংশ্লিষ্ট Birthday Universe-এর Report action ব্যবহার করুন। Account support-এর জন্য Z-Pay support center খুলুন।',contactSupport:'Z-Pay Support খুলুন'
  });

  function currentLocale() { return localStorage.getItem(localeKey) === 'bn' ? 'bn' : 'en'; }
  const tr = (english, bangla) => currentLocale() === 'bn' ? bangla : english;
  function applyLocale(locale) {
    const lang = locale === 'bn' ? 'bn' : 'en';
    localStorage.setItem(localeKey, lang);
    document.documentElement.lang = lang;
    $$('[data-copy]').forEach(node => {
      const key = node.dataset.copy;
      if (copy[lang]?.[key]) node.textContent = copy[lang][key];
    });
    $$('[data-locale]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.locale === lang)));
  }

  function toast(message) {
    const node = $('#birthdayToast');
    if (!node) return;
    node.textContent = String(message || '');
    node.hidden = false;
    window.clearTimeout(toast.timer);
    toast.timer = window.setTimeout(() => { node.hidden = true; }, 3200);
  }

  function showError(node, error) {
    if (!node) return;
    node.textContent = error instanceof Error ? error.message : String(error || 'Something went wrong.');
    node.hidden = false;
    node.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  async function copyText(value) {
    const text = String(value || '');
    try {
      await navigator.clipboard.writeText(text);
    } catch (_error) {
      const input = document.createElement('textarea');
      input.value = text;
      input.setAttribute('readonly', '');
      input.style.position = 'fixed';
      input.style.opacity = '0';
      document.body.append(input);
      input.select();
      document.execCommand('copy');
      input.remove();
    }
  }

  function randomHex(bytes = 16) {
    const data = crypto.getRandomValues(new Uint8Array(bytes));
    return Array.from(data, value => value.toString(16).padStart(2, '0')).join('').toUpperCase();
  }

  async function decodePhoto(file) {
    if (typeof createImageBitmap === 'function') {
      try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        return { source: bitmap, width: bitmap.width, height: bitmap.height, close: () => bitmap.close() };
      } catch (_error) {
      }
    }
    const url = URL.createObjectURL(file);
    const image = new Image();
    try {
      image.src = url;
      await image.decode();
      return { source: image, width: image.naturalWidth, height: image.naturalHeight, close: () => URL.revokeObjectURL(url) };
    } catch (error) {
      URL.revokeObjectURL(url);
      throw error;
    }
  }

  async function preparePhotoUpload(file) {
    let decoded = null;
    try {
      decoded = await decodePhoto(file);
      const maximumEdge = 1600;
      const maximumOptimizedBytes = 700 * 1024;
      const scale = Math.min(1, maximumEdge / Math.max(decoded.width, decoded.height));
      if (scale === 1 && file.size <= maximumOptimizedBytes) return file;
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(decoded.width * scale));
      canvas.height = Math.max(1, Math.round(decoded.height * scale));
      const context = canvas.getContext('2d', { alpha: true });
      if (!context) return file;
      context.imageSmoothingEnabled = true;
      context.imageSmoothingQuality = 'high';
      context.drawImage(decoded.source, 0, 0, canvas.width, canvas.height);
      const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/webp', .88));
      if (!(blob instanceof Blob) || blob.size <= 0 || blob.size > 5 * 1024 * 1024) return file;
      const baseName = String(file.name || 'birthday-photo').replace(/\.[^.]+$/u, '').slice(0, 100) || 'birthday-photo';
      return new File([blob], `${baseName}.webp`, { type: 'image/webp', lastModified: file.lastModified || Date.now() });
    } catch (_error) {
      return file;
    } finally {
      decoded?.close();
    }
  }

  function recoveryCode() {
    const alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    const bytes = crypto.getRandomValues(new Uint8Array(20));
    const raw = Array.from(bytes, value => alphabet[value % alphabet.length]).join('');
    return raw.match(/.{1,5}/g).join('-');
  }

  function safeState() {
    try { return JSON.parse(sessionStorage.getItem(stateKey) || '{}') || {}; } catch (_error) { return {}; }
  }

  function renderTemplateChoices(container, templates, selected, onSelect, catalog = false) {
    if (!container) return;
    container.replaceChildren();
    templates.forEach(template => {
      if (catalog) {
        const article = document.createElement('article');
        article.dataset.templateId = template.id;
        const image = new Image();
        image.src = template.preview_image;
        image.alt = `${template.name} Birthday Universe preview`;
        image.loading = 'lazy';
        const body = document.createElement('div');
        const title = document.createElement('strong');
        title.textContent = template.name;
        const description = document.createElement('p');
        description.textContent = template.description;
        body.append(title, description);
        article.append(image, body);
        container.append(article);
        return;
      }
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'template-option';
      button.dataset.templateId = template.id;
      button.setAttribute('aria-pressed', String(template.id === selected));
      const image = new Image();
      image.src = template.preview_image;
      image.alt = `${template.name} preview`;
      image.loading = 'lazy';
      const body = document.createElement('div');
      const title = document.createElement('strong');
      title.textContent = template.name;
      const description = document.createElement('span');
      description.textContent = template.description;
      body.append(title, description);
      button.append(image, body);
      button.addEventListener('click', () => {
        $$('[data-template-id]', container).forEach(item => item.setAttribute('aria-pressed', 'false'));
        button.setAttribute('aria-pressed', 'true');
        onSelect(template.id);
      });
      container.append(button);
    });
  }

  function trustedAdFrame(rawUrl) {
    try {
      const url = new URL(String(rawUrl || ''), window.location.origin);
      const pageHost = window.location.hostname.toLowerCase();
      const allowedHosts = pageHost === 'zsky24.com'
        ? new Set(['www.zsky24.com'])
        : pageHost === 'www.zsky24.com'
          ? new Set(['zsky24.com'])
          : new Set([pageHost]);
      return allowedHosts.has(url.hostname.toLowerCase())
        && url.pathname === '/api/znews/public/ad_frame.php'
        && Boolean(url.searchParams.get('permit')) ? url.toString() : '';
    } catch (_error) {
      return '';
    }
  }

  function mountAd(container, delivery) {
    const frameUrl = trustedAdFrame(delivery?.frame_url);
    if (!container || delivery?.enabled !== true || !frameUrl) return false;
    const frame = document.createElement('iframe');
    frame.src = frameUrl;
    frame.title = 'Advertisement';
    frame.loading = 'lazy';
    frame.referrerPolicy = 'strict-origin-when-cross-origin';
    frame.setAttribute('credentialless', '');
    frame.setAttribute('sandbox', 'allow-scripts allow-same-origin allow-popups allow-popups-to-escape-sandbox allow-top-navigation-by-user-activation');
    frame.width = delivery.width || '100%';
    frame.height = delivery.height || 300;
    const channel = String(delivery.resize_channel || '');
    if (channel) {
      window.addEventListener('message', event => {
        if (event.source !== frame.contentWindow || event.data?.type !== 'znews:adsterra-native-size' || event.data?.channel !== channel) return;
        const height = Math.max(90, Math.min(1600, Math.ceil(Number(event.data.height || 0))));
        if (Number.isFinite(height)) frame.height = String(height);
      });
    }
    container.append(frame);
    container.hidden = false;
    return true;
  }

  async function initCreate() {
    const form = $('#birthdayCreateForm');
    if (!form) return;
    const errorNode = $('#formError');
    const next = $('#nextStep');
    const previous = $('#previousStep');
    const restored = safeState();
    let step = 1;
    let config;
    let selectedTemplate = restored.template_id || 'cosmic';
    let selectedMusic = restored.music_id || '';
    let selectedAudioMode = ['AMBIENT','NONE','PLATFORM','CUSTOM'].includes(restored.audio_mode)
      ? restored.audio_mode
      : (selectedMusic ? 'PLATFORM' : 'AMBIENT');
    let photoFile = null;
    let photoUrl = '';
    let audioFile = null;
    let audioDuration = 0;
    let photoUploadRequestId = randomHex(16);
    let audioUploadRequestId = randomHex(16);
    let draftAttemptToken = randomHex(16);
    let draftAttemptPending = false;

    const monthSelect = $('#birthdayMonth');
    const monthNames = currentLocale() === 'bn'
      ? ['জানুয়ারি','ফেব্রুয়ারি','মার্চ','এপ্রিল','মে','জুন','জুলাই','আগস্ট','সেপ্টেম্বর','অক্টোবর','নভেম্বর','ডিসেম্বর']
      : ['January','February','March','April','May','June','July','August','September','October','November','December'];
    monthNames.forEach((name, index) => {
      const option = document.createElement('option'); option.value = String(index + 1); option.textContent = name; monthSelect.append(option);
    });
    $('#birthdayYear').max = String(new Date().getFullYear());
    ['name','birthday_day','birthday_month','birthday_year','sender_name','message'].forEach(name => {
      const input = form.elements[name];
      if (input && restored[name] !== undefined) input.value = restored[name];
    });
    if (restored.visibility) {
      const radio = form.querySelector(`input[name="visibility"][value="${restored.visibility}"]`);
      if (radio) radio.checked = true;
    }

    const updateCounters = () => {
      $('#nameCount').textContent = String($('#birthdayName').value.length);
      $('#senderCount').textContent = String($('#senderName').value.length);
      $('#messageCount').textContent = String($('#birthdayMessage').value.length);
    };
    ['#birthdayName','#senderName','#birthdayMessage'].forEach(selector => $(selector).addEventListener('input', updateCounters));
    updateCounters();

    function persist() {
      const data = Object.fromEntries(new FormData(form).entries());
      data.template_id = selectedTemplate;
      data.music_id = selectedMusic;
      data.audio_mode = selectedAudioMode;
      sessionStorage.setItem(stateKey, JSON.stringify(data));
    }

    function showStep(value) {
      step = Math.max(1, Math.min(7, value));
      $$('.form-step', form).forEach(section => { const active = Number(section.dataset.step) === step; section.hidden = !active; section.classList.toggle('active', active); });
      $('#stepCount').textContent = `${step} / 7`;
      $('#stepProgress').style.width = `${step / 7 * 100}%`;
      previous.hidden = step === 1;
      next.textContent = step === 7 ? (currentLocale() === 'bn' ? 'Preview দেখুন' : 'Preview Universe') : copy[currentLocale()].continueButton;
      errorNode.hidden = true;
      form.scrollIntoView({ behavior: 'smooth', block: 'start' });
      persist();
    }

    function validateStep() {
      let field = null;
      if (step === 1 && !$('#birthdayName').value.trim()) field = $('#birthdayName');
      if (step === 2) {
        const day = Number($('#birthdayDay').value); const month = Number($('#birthdayMonth').value);
        const year = Number($('#birthdayYear').value || 2000);
        const check = new Date(Date.UTC(year, month - 1, day));
        if (!day || !month || check.getUTCDate() !== day || check.getUTCMonth() !== month - 1) field = !day ? $('#birthdayDay') : $('#birthdayMonth');
      }
      if (step === 6 && !selectedTemplate) throw new Error('Please choose a template.');
      if (step === 7 && selectedAudioMode === 'CUSTOM' && !audioFile) {
        $('#birthdayAudio').focus();
        throw new Error(currentLocale() === 'bn' ? 'একটি ৩০ সেকেন্ডের audio বেছে নিন।' : 'Choose a custom audio file up to 30 seconds.');
      }
      if (step === 7 && selectedAudioMode === 'CUSTOM' && !$('#audioRightsConfirmed').checked) {
        $('#audioRightsConfirmed').focus();
        throw new Error(currentLocale() === 'bn' ? 'Audio ব্যবহারের অনুমতি নিশ্চিত করুন।' : 'Confirm that you have permission to use this audio.');
      }
      if (step === 7 && !$('#consentConfirmed').checked) field = $('#consentConfirmed');
      if (field) { field.focus(); throw new Error(step === 7 ? 'Please confirm that you have permission to publish this content.' : 'Please complete this step.'); }
    }

    try {
      config = await api.config();
      renderTemplateChoices($('#templatePicker'), config.templates || [], selectedTemplate, value => { selectedTemplate = value; persist(); });
      const musicPicker = $('#musicPicker');
      musicPicker.replaceChildren();
      const audioOption = (mode, iconValue, titleValue, detailValue, musicId = '') => {
        const button = document.createElement('button'); button.type = 'button'; button.className = 'music-option'; button.dataset.audioMode = mode; button.dataset.musicId = musicId;
        button.setAttribute('aria-pressed', String(selectedAudioMode === mode && (mode !== 'PLATFORM' || selectedMusic === musicId)));
        const icon = document.createElement('span'); icon.textContent = iconValue; const text = document.createElement('span'); const strong = document.createElement('strong'); strong.textContent = titleValue; const small = document.createElement('small'); small.textContent = detailValue; text.append(strong, small); button.append(icon, text);
        button.addEventListener('click', () => {
          selectedAudioMode = mode;
          selectedMusic = mode === 'PLATFORM' ? musicId : '';
          $$('.music-option', musicPicker).forEach(item => item.setAttribute('aria-pressed','false'));
          button.setAttribute('aria-pressed','true');
          $('#customAudioPanel').hidden = mode !== 'CUSTOM';
          persist();
        });
        musicPicker.append(button);
      };
      audioOption('AMBIENT', '✦', currentLocale() === 'bn' ? 'Cosmic ambience' : 'Cosmic ambience', currentLocale() === 'bn' ? 'Universe-এ ঢুকলে অলৌকিক space sound বাজবে' : 'Mystical space sound begins when the Universe opens');
      audioOption('NONE', '×', currentLocale() === 'bn' ? 'শব্দ ছাড়া' : 'No sound', currentLocale() === 'bn' ? 'নীরব অভিজ্ঞতা রাখুন' : 'Keep the experience silent');
      audioOption('CUSTOM', '＋', currentLocale() === 'bn' ? 'নিজের audio' : 'My audio', currentLocale() === 'bn' ? 'সর্বোচ্চ ৩০ সেকেন্ডের file দিন' : 'Upload a file up to 30 seconds');
      (config.music || []).forEach(track => {
        audioOption('PLATFORM', '♪', track.name, track.duration ? `${Math.floor(track.duration/60)}:${String(track.duration%60).padStart(2,'0')}` : 'Licensed track', track.id);
      });
      $('#customAudioPanel').hidden = selectedAudioMode !== 'CUSTOM';
      if (!config.settings?.allow_public_indexing) $('#publicVisibilityOption').hidden = true;
    } catch (error) {
      showError(errorNode, error);
      next.disabled = true;
    }

    $('#birthdayPhoto').addEventListener('change', event => {
      const candidate = event.target.files?.[0] || null;
      if (!candidate) return;
      const maximum = Number(config?.settings?.photo_max_bytes || 5 * 1024 * 1024);
      if (!['image/jpeg','image/png','image/webp'].includes(candidate.type) || candidate.size > maximum) {
        event.target.value = ''; showError(errorNode, new Error(`Choose a JPEG, PNG or WebP image up to ${Math.floor(maximum / 1048576)} MB.`)); return;
      }
      photoFile = candidate;
      photoUploadRequestId = randomHex(16);
      if (photoUrl) URL.revokeObjectURL(photoUrl);
      photoUrl = URL.createObjectURL(candidate);
      $('#photoPreview').closest('.photo-picker')?.classList.add('has-photo');
      $('#photoPreview').src = photoUrl; $('#photoPreview').hidden = false; $('#photoPlaceholder').hidden = true; $('#removePhoto').hidden = false;
    });
    $('#removePhoto').addEventListener('click', () => {
      photoFile = null; photoUploadRequestId = randomHex(16); $('#birthdayPhoto').value = ''; $('#photoPreview').hidden = true; $('#photoPreview').removeAttribute('src'); $('#photoPreview').closest('.photo-picker')?.classList.remove('has-photo'); $('#photoPlaceholder').hidden = false; $('#removePhoto').hidden = true; if (photoUrl) URL.revokeObjectURL(photoUrl); photoUrl = '';
    });
    const inspectAudioDuration = file => new Promise((resolve, reject) => {
      const url = URL.createObjectURL(file);
      const audio = document.createElement('audio');
      const done = callback => { window.clearTimeout(timer); audio.removeAttribute('src'); audio.load(); URL.revokeObjectURL(url); callback(); };
      const timer = window.setTimeout(() => done(() => reject(new Error('Audio duration could not be read.'))), 8000);
      audio.preload = 'metadata';
      audio.addEventListener('loadedmetadata', () => { const duration = audio.duration; done(() => Number.isFinite(duration) && duration > 0 ? resolve(duration) : reject(new Error('Audio duration could not be read.'))); }, { once: true });
      audio.addEventListener('error', () => done(() => reject(new Error('Choose a valid MP3, M4A or OGG audio file.'))), { once: true });
      audio.src = url;
    });
    $('#birthdayAudio').addEventListener('change', async event => {
      const candidate = event.target.files?.[0] || null;
      if (!candidate) return;
      const allowed = ['audio/mpeg','audio/mp3','audio/mp4','audio/x-m4a','audio/ogg','application/ogg'];
      const extensionOk = /\.(?:mp3|m4a|ogg)$/i.test(candidate.name || '');
      const maximum = Number(config?.settings?.audio_max_bytes || 10 * 1024 * 1024);
      if ((!allowed.includes(candidate.type) && !(candidate.type === '' && extensionOk)) || candidate.size > maximum) {
        event.target.value = '';
        showError(errorNode, new Error(`Choose an MP3, M4A or OGG file up to ${Math.floor(maximum / 1048576)} MB.`));
        return;
      }
      try {
        const duration = await inspectAudioDuration(candidate);
        const durationLimit = Number(config?.settings?.audio_max_duration_seconds || 30);
        if (duration > durationLimit + 0.1) throw new Error(`Custom audio must be ${durationLimit} seconds or shorter.`);
        audioFile = candidate;
        audioDuration = duration;
        audioUploadRequestId = randomHex(16);
        $('#audioFileName').textContent = `${candidate.name} · ${Math.ceil(duration)}s`;
        $('#audioSelection').hidden = false;
        errorNode.hidden = true;
      } catch (error) {
        event.target.value = '';
        audioFile = null;
        audioDuration = 0;
        $('#audioSelection').hidden = true;
        showError(errorNode, error);
      }
    });
    $('#removeAudio').addEventListener('click', () => {
      audioFile = null; audioDuration = 0; audioUploadRequestId = randomHex(16); $('#birthdayAudio').value = ''; $('#audioSelection').hidden = true; $('#audioFileName').textContent = '';
    });
    previous.addEventListener('click', () => showStep(step - 1));
    next.addEventListener('click', async () => {
      try {
        validateStep();
        if (step < 7) { showStep(step + 1); return; }
        next.disabled = true; next.textContent = currentLocale() === 'bn' ? 'Draft তৈরি হচ্ছে…' : 'Creating preview…';
        const draftToken = draftAttemptToken;
        draftAttemptPending = true;
        const payload = {
          name: $('#birthdayName').value, birthday_day: $('#birthdayDay').value, birthday_month: $('#birthdayMonth').value, birthday_year: $('#birthdayYear').value,
          sender_name: $('#senderName').value, message: $('#birthdayMessage').value, template_id: selectedTemplate, music_id: selectedMusic, audio_mode: selectedAudioMode,
          locale: currentLocale(), visibility: form.querySelector('input[name="visibility"]:checked')?.value || 'UNLISTED', share_photo: $('#sharePhoto').checked,
          consent_confirmed: $('#consentConfirmed').checked, draft_token: draftToken
        };
        const created = await api.createDraft(payload);
        let draft = created.draft;
        if (photoFile) {
          next.textContent = currentLocale() === 'bn' ? 'ছবি প্রস্তুত করা হচ্ছে…' : 'Preparing photo…';
          const preparedPhoto = await preparePhotoUpload(photoFile);
          next.textContent = currentLocale() === 'bn' ? 'ছবি নিরাপদে রাখা হচ্ছে…' : 'Saving photo…';
          const upload = new FormData(); upload.append('image', preparedPhoto, preparedPhoto.name || 'birthday-photo.webp'); upload.append('draft_id', draft.id); upload.append('draft_token', draftToken); upload.append('upload_request_id', photoUploadRequestId);
          const uploaded = await api.uploadPhoto(upload); draft = uploaded.draft;
        }
        if (selectedAudioMode === 'CUSTOM' && audioFile) {
          next.textContent = currentLocale() === 'bn' ? 'Audio যাচাই হচ্ছে…' : 'Checking audio…';
          const upload = new FormData(); upload.append('audio', audioFile); upload.append('draft_id', draft.id); upload.append('draft_token', draftToken); upload.append('upload_request_id', audioUploadRequestId); upload.append('rights_confirmed', 'true');
          const uploaded = await api.uploadAudio(upload); draft = uploaded.draft;
        }
        sessionStorage.setItem(`birthday_draft_token_${draft.id}`, draftToken);
        persist();
        window.location.assign(`/birthday/preview/${encodeURIComponent(draft.id)}`);
      } catch (error) {
        showError(errorNode, error); next.disabled = false; next.textContent = copy[currentLocale()].continueButton;
      }
    });
    form.addEventListener('input', () => {
      if (draftAttemptPending && !next.disabled) {
        draftAttemptToken = randomHex(16);
        draftAttemptPending = false;
      }
      persist();
    });
    showStep(1);
  }

  async function initPreview() {
    const draftId = document.body.dataset.draftId || $('.preview-shell')?.dataset.draft || '';
    const token = sessionStorage.getItem(`birthday_draft_token_${draftId}`) || '';
    const errorNode = $('#previewError');
    const generate = $('#generateUniverse');
    if (!draftId || !token) { showError(errorNode, new Error('This private preview is no longer available in this browser.')); generate.disabled = true; return; }
    try {
      const result = await api.draft(draftId, token);
      const draft = result.draft;
      if (draft.photo_url) {
        try {
          const blob = await api.draftPhotoBlob(draft.photo_url, token);
          const objectUrl = URL.createObjectURL(blob);
          draft.photo_url = objectUrl;
          window.addEventListener('pagehide', () => URL.revokeObjectURL(objectUrl), { once: true });
        } catch (_photoError) {
          draft.photo_url = '';
          showError(errorNode, new Error(currentLocale() === 'bn'
            ? 'Photo preview এখন load হয়নি। ছবিটি draft-এ নিরাপদে যুক্ত আছে; generate করলে আবার চেষ্টা হবে।'
            : 'The photo preview is temporarily unavailable. It remains safely attached and will be retried after generation.'));
        }
      }
      draft.star_id = `${String(draft.name || 'STAR').replace(/[^A-Za-z0-9]/g,'').slice(0,3).toUpperCase() || 'ZST'}-${draft.birthday_day}${draft.birthday_month}-PREVIEW`;
      window.BirthdayTemplates.render($('#previewUniverse'), draft, {
        preview: true,
        loadSoundtrack: draft.soundtrack?.mode === 'CUSTOM' && draft.soundtrack?.url
          ? () => api.draftAudioBuffer(draft.soundtrack.url, token)
          : null
      });
      window.BirthdayAdService.capability({ context: 'preview', draft_id: draftId }, token).then(data => mountAd($('#birthdayPreviewAd'), data.delivery)).catch(() => {});
      generate.addEventListener('click', async () => {
        const recoveryKey = `birthday_generation_recovery_${draftId}`;
        const idempotencyKey = `birthday_generation_idempotency_${draftId}`;
        const recovery = sessionStorage.getItem(recoveryKey) || recoveryCode();
        const idempotency = sessionStorage.getItem(idempotencyKey) || `birthday-${draftId}-${randomHex(12)}`;
        sessionStorage.setItem(recoveryKey, recovery);
        sessionStorage.setItem(idempotencyKey, idempotency);
        generate.disabled = true; generate.textContent = currentLocale() === 'bn' ? 'Universe তৈরি হচ্ছে…' : 'Generating Universe…'; errorNode.hidden = true;
        try {
          const created = await api.generate({ draft_id: draftId, draft_token: token, recovery_code: recovery, idempotency_key: idempotency });
          const universe = created.universe;
          sessionStorage.setItem(`birthday_claim_recovery_${universe.slug}`, recovery);
          $('#generationPanel').hidden = true; $('#generationSuccess').hidden = false; $('#generatedUrl').value = universe.url; $('#recoveryCode').textContent = recovery; $('#openUniverse').href = `/u/${encodeURIComponent(universe.slug)}`;
          $('#copyGeneratedUrl').onclick = () => copyText(universe.url).then(() => toast('Link copied.'));
          $('#copyRecoveryCode').onclick = () => copyText(recovery).then(() => toast('Recovery code copied.'));
          $('#shareGenerated').onclick = () => shareLink(universe.url, `${universe.name}'s Birthday Universe`);
          sessionStorage.removeItem(stateKey);
          sessionStorage.removeItem(recoveryKey);
          sessionStorage.removeItem(idempotencyKey);
          $('#generationSuccess').scrollIntoView({ behavior: 'smooth', block: 'center' });
        } catch (error) { showError(errorNode, error); generate.disabled = false; generate.textContent = copy[currentLocale()].generateButton; }
      });
    } catch (error) { showError(errorNode, error); generate.disabled = true; }
  }

  async function shareLink(url, title) {
    if (navigator.share) {
      try { await navigator.share({ title, text: title, url }); return; } catch (error) { if (error?.name === 'AbortError') return; }
    }
    await copyText(url); toast(currentLocale() === 'bn' ? 'Link কপি হয়েছে।' : 'Link copied.');
  }

  async function initTemplates() {
    try { const config = await api.config(); renderTemplateChoices($('#templateCatalog'), config.templates || [], '', () => {}, true); }
    catch (error) { $('#templateCatalog').textContent = error.message; }
  }

  function loginCard(slug) {
    const wrapper = document.createElement('section'); wrapper.className = 'manage-card';
    const title = document.createElement('h2'); title.textContent = currentLocale() === 'bn' ? 'Z-Pay login প্রয়োজন' : 'Z-Pay login required';
    const text = document.createElement('p'); text.textContent = currentLocale() === 'bn' ? 'Universe claim, edit, renew বা delete করতে login করুন।' : 'Sign in to claim, edit, renew or delete this Universe.';
    const link = document.createElement('a'); link.className = 'button primary'; link.textContent = currentLocale() === 'bn' ? 'Z-Pay দিয়ে Login' : 'Sign in with Z-Pay';
    link.href = `https://zpayswift.com/user/znews?return=${encodeURIComponent(`/birthday/manage/${slug}`)}`;
    wrapper.append(title, text, link); return wrapper;
  }

  function claimCard(slug, onClaimed) {
    const wrapper = document.createElement('section'); wrapper.className = 'manage-card';
    const title = document.createElement('h2'); title.textContent = currentLocale() === 'bn' ? 'Ownership claim করুন' : 'Claim ownership';
    const text = document.createElement('p'); text.textContent = currentLocale() === 'bn' ? 'Universe তৈরির সময় পাওয়া recovery code লিখুন।' : 'Enter the recovery code shown when this Universe was created.';
    const label = document.createElement('label'); label.className = 'field'; const span = document.createElement('span'); span.textContent = 'Recovery code'; const input = document.createElement('input'); input.autocomplete = 'off'; input.maxLength = 32; input.value = sessionStorage.getItem(`birthday_claim_recovery_${slug}`) || ''; label.append(span,input);
    const error = document.createElement('p'); error.className = 'form-error'; error.hidden = true;
    const button = document.createElement('button'); button.className = 'button primary'; button.type = 'button'; button.textContent = currentLocale() === 'bn' ? 'Claim Universe' : 'Claim Universe';
    button.addEventListener('click', async () => { try { button.disabled = true; const result = await api.manageAction({ action:'CLAIM', slug, recovery_code:input.value }); sessionStorage.removeItem(`birthday_claim_recovery_${slug}`); input.value=''; onClaimed(result.universe); } catch (err) { showError(error,err); button.disabled=false; } });
    wrapper.append(title,text,label,error,button); return wrapper;
  }

  function manageEditor(universe, root) {
    root.replaceChildren();
    const card = document.createElement('section'); card.className = 'manage-card';
    const title = document.createElement('h2'); title.textContent = universe.name;
    const text = document.createElement('p'); text.textContent = tr('The share link stays the same. You can edit the content or renew its lifetime.', 'Share link একই থাকবে। নিচের তথ্য edit অথবা lifetime renew করতে পারবেন।');
    const meta = document.createElement('div'); meta.className = 'manage-meta';
    [`★ ${universe.star_id}`, `${tr('Expires','মেয়াদ')} ${new Date(universe.expires_at*1000).toLocaleDateString(currentLocale()==='bn'?'bn-BD':'en-US')}`, universe.visibility].forEach(value => { const item=document.createElement('span'); item.textContent=value; meta.append(item); });
    const form = document.createElement('form'); form.className = 'manage-form'; form.noValidate = true;
    const field = (labelText,name,value,type='text',wide=false) => { const label=document.createElement('label'); label.className=`field${wide?' wide':''}`; const span=document.createElement('span'); span.textContent=labelText; const input=type==='textarea'?document.createElement('textarea'):document.createElement('input'); input.name=name; input.value=value ?? ''; if(type!=='textarea') input.type=type; if(type==='textarea') input.maxLength=500; label.append(span,input); return label; };
    form.append(field(tr('Birthday person name','জন্মদিনের ব্যক্তির নাম'),'name',universe.name),field(tr('Sender name','প্রেরকের নাম'),'sender_name',universe.sender_name),field(tr('Day','দিন'),'birthday_day',universe.birthday_day,'number'),field(tr('Month','মাস'),'birthday_month',universe.birthday_month,'number'),field(tr('Year (optional)','সাল (ঐচ্ছিক)'),'birthday_year',universe.birthday_year || '','number'),field(tr('Birthday message','জন্মদিনের বার্তা'),'message',universe.message,'textarea',true));
    const configRow = document.createElement('div'); configRow.className='wide manage-form';
    const templateLabel=document.createElement('label'); templateLabel.className='field'; const templateSpan=document.createElement('span'); templateSpan.textContent=tr('Template','টেমপ্লেট'); const templateSelect=document.createElement('select'); templateSelect.name='template_id'; templateLabel.append(templateSpan,templateSelect);
    const musicLabel=document.createElement('label'); musicLabel.className='field'; const musicSpan=document.createElement('span'); musicSpan.textContent=tr('Soundtrack','সাউন্ডট্র্যাক'); const musicSelect=document.createElement('select'); musicSelect.name='soundtrack_choice'; musicLabel.append(musicSpan,musicSelect);
    const visibilityLabel=document.createElement('label'); visibilityLabel.className='field'; const visibilitySpan=document.createElement('span'); visibilitySpan.textContent=tr('Search visibility','Search visibility'); const visibilitySelect=document.createElement('select'); visibilitySelect.name='visibility'; [['UNLISTED','Unlisted'],['PUBLIC','Public']].forEach(([value,label])=>{const option=document.createElement('option');option.value=value;option.textContent=label;option.selected=value===universe.visibility;visibilitySelect.append(option);}); visibilityLabel.append(visibilitySpan,visibilitySelect);
    configRow.append(templateLabel,musicLabel,visibilityLabel); form.append(configRow);
    const sharePhotoLabel=document.createElement('label'); sharePhotoLabel.className='check-row wide'; const sharePhoto=document.createElement('input'); sharePhoto.type='checkbox'; sharePhoto.name='share_photo'; sharePhoto.checked=universe.share_photo===true; const sharePhotoText=document.createElement('span'); sharePhotoText.textContent=tr('Use the photo in social link previews.','Social link preview-তে ছবিটি ব্যবহার করুন।'); sharePhotoLabel.append(sharePhoto,sharePhotoText); form.append(sharePhotoLabel);
    const error=document.createElement('p'); error.className='form-error wide'; error.hidden=true; form.append(error);
    const actions=document.createElement('div'); actions.className='manage-actions wide';
    const save=document.createElement('button'); save.type='submit'; save.className='button primary'; save.textContent=tr('Save changes','পরিবর্তন সংরক্ষণ');
    const renew=document.createElement('button'); renew.type='button'; renew.className='button secondary'; renew.textContent=tr('Renew 90 days','৯০ দিন renew করুন');
    const photoLabel=document.createElement('label'); photoLabel.className='button secondary'; photoLabel.textContent=tr('Replace photo','ছবি বদলান'); const photo=document.createElement('input'); photo.type='file'; photo.accept='image/jpeg,image/png,image/webp'; photo.hidden=true; photoLabel.append(photo);
    const clearPhoto=document.createElement('button'); clearPhoto.type='button'; clearPhoto.className='button secondary'; clearPhoto.textContent=tr('Remove photo','ছবি সরান'); clearPhoto.hidden=!universe.photo_url;
    const remove=document.createElement('button'); remove.type='button'; remove.className='button danger'; remove.textContent=tr('Delete Universe','Universe মুছুন');
    actions.append(save,renew,photoLabel,clearPhoto,remove); form.append(actions); card.append(title,text,meta,form); root.append(card);
    api.config().then(config => {
      (config.templates||[]).forEach(item=>{const option=document.createElement('option');option.value=item.id;option.textContent=item.name;option.selected=item.id===universe.template?.id;templateSelect.append(option);});
      [['AMBIENT',tr('Cosmic ambience','Cosmic ambience')],['NONE',tr('No sound','শব্দ ছাড়া')]].forEach(([value,label])=>{const option=document.createElement('option');option.value=value;option.textContent=label;option.selected=value===(universe.audio_mode||'NONE');musicSelect.append(option);});
      if(universe.audio_mode==='CUSTOM'){const option=document.createElement('option');option.value='CUSTOM';option.textContent=tr('Custom audio','নিজের audio');option.selected=true;musicSelect.append(option);}
      (config.music||[]).forEach(item=>{const option=document.createElement('option');option.value=`PLATFORM:${item.id}`;option.textContent=item.name;option.selected=universe.audio_mode==='PLATFORM'&&item.id===universe.music?.id;musicSelect.append(option);});
    });
    form.addEventListener('submit',async event=>{event.preventDefault();try{save.disabled=true;const data=Object.fromEntries(new FormData(form).entries());const soundtrack=String(data.soundtrack_choice||'NONE');delete data.soundtrack_choice;data.audio_mode=soundtrack.startsWith('PLATFORM:')?'PLATFORM':soundtrack;data.music_id=soundtrack.startsWith('PLATFORM:')?soundtrack.slice(9):'';const result=await api.manageAction({action:'UPDATE',slug:universe.slug,universe:{...data,locale:universe.locale,share_photo:sharePhoto.checked,consent_confirmed:true}});toast(tr('Universe updated.','Universe update হয়েছে।'));manageEditor(result.universe,root);}catch(err){showError(error,err);save.disabled=false;}});
    renew.addEventListener('click',async()=>{try{renew.disabled=true;const result=await api.manageAction({action:'RENEW',slug:universe.slug});toast(tr('Universe renewed.','Universe renew হয়েছে।'));manageEditor(result.universe,root);}catch(err){showError(error,err);renew.disabled=false;}});
    photo.addEventListener('change',async()=>{const file=photo.files?.[0];if(!file)return;try{const preparedPhoto=await preparePhotoUpload(file);const upload=new FormData();upload.append('image',preparedPhoto,preparedPhoto.name||'birthday-photo.webp');upload.append('slug',universe.slug);upload.append('upload_request_id',randomHex(16));const result=await api.uploadPhoto(upload,true);toast(tr('Photo updated.','ছবি update হয়েছে।'));manageEditor(result.universe,root);}catch(err){showError(error,err);}});
    clearPhoto.addEventListener('click',async()=>{if(!window.confirm(tr('Remove this photo from the Birthday Universe?','Birthday Universe থেকে ছবিটি সরাবেন?')))return;try{clearPhoto.disabled=true;const result=await api.manageAction({action:'REMOVE_PHOTO',slug:universe.slug});toast(tr('Photo removed.','ছবি সরানো হয়েছে।'));manageEditor(result.universe,root);}catch(err){showError(error,err);clearPhoto.disabled=false;}});
    remove.addEventListener('click',async()=>{if(!window.confirm(tr('Delete this Birthday Universe? This cannot be undone.','এই Birthday Universe মুছবেন? এটি আর ফেরানো যাবে না।')))return;try{remove.disabled=true;await api.manageAction({action:'DELETE',slug:universe.slug});root.replaceChildren();const done=document.createElement('section');done.className='manage-card';const doneTitle=document.createElement('h2');doneTitle.textContent=tr('Universe deleted','Universe মুছে দেওয়া হয়েছে');const doneText=document.createElement('p');doneText.textContent=tr('The public link is no longer available.','Public link আর ব্যবহার করা যাবে না।');done.append(doneTitle,doneText);root.append(done);}catch(err){showError(error,err);remove.disabled=false;}});
  }

  async function initManage() {
    const slug = document.body.dataset.slug || $('.manage-shell')?.dataset.slug || '';
    const root = $('#manageState');
    if (!slug) { root.textContent = 'Universe link is invalid.'; return; }
    const handoff = new URLSearchParams(location.hash.replace(/^#/, '')).get('handoff') || '';
    if (handoff) {
      try { await api.exchangeHandoff(handoff); history.replaceState(null,'',location.pathname); } catch (error) { toast(error.message); }
    }
    if (!api.sessionToken) { root.replaceChildren(loginCard(slug)); return; }
    try { const result = await api.manage(slug); manageEditor(result.universe, root); }
    catch (error) {
      if (error.code === 'BIRTHDAY_OWNER_REQUIRED' || error.status === 403) root.replaceChildren(claimCard(slug, universe => manageEditor(universe,root)));
      else if (error.status === 401) root.replaceChildren(loginCard(slug));
      else root.textContent = error.message;
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    applyLocale(currentLocale());
    $$('[data-locale]').forEach(button => button.addEventListener('click', () => applyLocale(button.dataset.locale)));
    if (page === 'create') void initCreate();
    if (page === 'preview') void initPreview();
    if (page === 'templates') void initTemplates();
    if (page === 'manage') void initManage();
  });
})();
