(() => {
  'use strict';

  const DEVICE_CATEGORY = 'MOBILE_PRICING';
  const categories = Object.freeze([
    ['BD_NEWS', 'Bangladesh News'],
    ['INTERNATIONAL_NEWS', 'International News'],
    ['HEALTH', 'Health'],
    ['SPORTS', 'Sports'],
    ['ISLAMIC', 'Islamic'],
    ['JOKES', 'Jokes'],
    [DEVICE_CATEGORY, 'Device Specs & Pricing']
  ]);
  const deviceTypes = Object.freeze([
    ['MOBILE', 'Mobile phone'],
    ['LAPTOP', 'Laptop'],
    ['TABLET', 'Tablet'],
    ['SMARTWATCH', 'Smartwatch'],
    ['CAMERA', 'Camera'],
    ['ACCESSORY', 'Accessory'],
    ['OTHER', 'Other device']
  ]);
  const specFields = Object.freeze([
    ['Brand', 'e.g. Samsung'],
    ['Model', 'e.g. Galaxy S26 Ultra'],
    ['Price', 'e.g. BDT 149,999'],
    ['Processor', 'e.g. Snapdragon 8 Elite'],
    ['RAM', 'e.g. 12 GB'],
    ['Storage / ROM', 'e.g. 512 GB'],
    ['Display', 'e.g. 6.8-inch AMOLED, 120 Hz'],
    ['Camera', 'e.g. 200 MP + 50 MP'],
    ['Graphics', 'e.g. RTX 5070 8 GB'],
    ['Battery', 'e.g. 5000 mAh, 45 W'],
    ['Operating system', 'e.g. Android 16'],
    ['Connectivity', 'e.g. 5G, Wi-Fi 7, Bluetooth 5.4'],
    ['Warranty', 'e.g. 1 year official'],
    ['Other specifications', 'Add any remaining important features']
  ]);

  const text = (value) => String(value ?? '').trim();
  const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
  })[char]);
  const categoryIds = Object.freeze(categories.map(([id]) => id));

  function categoryLabel(value) {
    const id = text(value).toUpperCase();
    return categories.find(([candidate]) => candidate === id)?.[1] || '';
  }

  function categoryOptionsMarkup() {
    return categories.map(([id, label]) => `
      <button type="button" role="radio" aria-checked="false" data-category-option="${id}"><span>${label}</span><i aria-hidden="true"></i></button>`).join('');
  }

  function editorMarkup(prefix) {
    return `<section class="device-spec-editor" id="${prefix}DeviceEditor" hidden aria-labelledby="${prefix}DeviceHeading">
      <header><h2 id="${prefix}DeviceHeading">Device specifications</h2><p>Add only the details that apply to this device.</p></header>
      <label class="device-type-field" for="${prefix}DeviceType"><span>Device type</span>
        <select id="${prefix}DeviceType" required>
          <option value="">Choose device type</option>
          ${deviceTypes.map(([id, label]) => `<option value="${id}">${label}</option>`).join('')}
        </select>
      </label>
      <div class="device-spec-inputs">
        ${specFields.map(([label, placeholder]) => `<label><span>${label}</span><input type="text" maxlength="300" data-device-spec-label="${escapeHtml(label)}" placeholder="${escapeHtml(placeholder)}"></label>`).join('')}
      </div>
    </section>`;
  }

  function editorElements(root, prefix) {
    return {
      panel: root?.querySelector?.(`#${prefix}DeviceEditor`) || null,
      type: root?.querySelector?.(`#${prefix}DeviceType`) || null,
      inputs: [...(root?.querySelectorAll?.(`#${prefix}DeviceEditor [data-device-spec-label]`) || [])]
    };
  }

  function readEditor(root, prefix) {
    const fields = editorElements(root, prefix);
    return {
      deviceType: text(fields.type?.value).toUpperCase(),
      deviceSpecs: fields.inputs.map((input) => ({
        label: text(input.dataset.deviceSpecLabel),
        value: text(input.value)
      })).filter((item) => item.label && item.value)
    };
  }

  function setEditor(root, prefix, post = {}) {
    const fields = editorElements(root, prefix);
    if (fields.type) fields.type.value = text(post.device_type).toUpperCase();
    const byLabel = new Map((Array.isArray(post.device_specs) ? post.device_specs : []).map((item) => [
      text(item?.label).toLowerCase(), text(item?.value)
    ]));
    const extras = [];
    byLabel.forEach((value, label) => {
      if (!specFields.some(([known]) => known.toLowerCase() === label)) extras.push(`${label}: ${value}`);
    });
    fields.inputs.forEach((input) => {
      const label = text(input.dataset.deviceSpecLabel);
      input.value = byLabel.get(label.toLowerCase()) || (label === 'Other specifications' ? extras.join('; ') : '');
    });
  }

  function syncEditor(root, prefix, category) {
    const fields = editorElements(root, prefix);
    const active = text(category).toUpperCase() === DEVICE_CATEGORY;
    if (fields.panel) fields.panel.hidden = !active;
    if (fields.type) fields.type.required = active;
    return active;
  }

  function bindEditor(root, prefix, categoryInput, onChange) {
    const fields = editorElements(root, prefix);
    const notify = () => typeof onChange === 'function' && onChange();
    fields.type?.addEventListener('change', notify);
    fields.inputs.forEach((input) => input.addEventListener('input', notify));
    categoryInput?.addEventListener('change', () => {
      syncEditor(root, prefix, categoryInput.value);
      notify();
    });
    syncEditor(root, prefix, categoryInput?.value);
  }

  function isComplete(category, details) {
    if (text(category).toUpperCase() !== DEVICE_CATEGORY) return true;
    return Boolean(text(details?.deviceType) && Array.isArray(details?.deviceSpecs) && details.deviceSpecs.length);
  }

  function detailsMarkup(post, escape = escapeHtml) {
    if (text(post?.category).toUpperCase() !== DEVICE_CATEGORY) return '';
    const specs = Array.isArray(post?.device_specs) ? post.device_specs.filter((item) => text(item?.label) && text(item?.value)) : [];
    const type = deviceTypes.find(([id]) => id === text(post?.device_type).toUpperCase())?.[1] || '';
    if (!type && !specs.length) return '';
    return `<section class="post-device-specs" aria-label="Device specifications">
      <header><strong>Device specifications</strong>${type ? `<span>${escape(type)}</span>` : ''}</header>
      ${specs.length ? `<dl>${specs.map((item) => `<div><dt>${escape(text(item.label))}</dt><dd>${escape(text(item.value))}</dd></div>`).join('')}</dl>` : ''}
    </section>`;
  }

  window.ZNewsDeviceSpecs = Object.freeze({
    DEVICE_CATEGORY,
    categories,
    categoryIds,
    deviceTypes,
    specFields,
    categoryLabel,
    categoryOptionsMarkup,
    editorMarkup,
    readEditor,
    setEditor,
    syncEditor,
    bindEditor,
    isComplete,
    detailsMarkup
  });
})();
