(() => {
  'use strict';
  const shell = window.UserShell;
  const $ = (id) => document.getElementById(id);

  function icon(type) {
    if (type === 'email') return '<svg viewBox="0 0 24 24"><path d="M4 6h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Zm8 7.1L4.9 8H4v.8l8 5.7 8-5.7V8h-.9L12 13.1Z"/></svg>';
    if (type === 'phone') return '<svg viewBox="0 0 24 24"><path d="M6.6 10.8a15 15 0 0 0 6.6 6.6l2.2-2.2 5.7 1.8v4A19.9 19.9 0 0 1 1.5 2.6h4l1.8 5.7-2.2 2.2Z"/></svg>';
    return '<svg viewBox="0 0 24 24"><path d="M12 3C6.5 3 2 6.8 2 11.5c0 2.7 1.5 5.2 4 6.7V22l3.7-2.1c.7.1 1.5.2 2.3.2 5.5 0 10-3.8 10-8.5S17.5 3 12 3Z"/></svg>';
  }

  async function init() {
    await shell.ready;
    const data = await shell.get('support_config', {}, 'Loading support...');
    const config = data.config || {};
    $('supportNotice').textContent = config.support_notice || 'Never share your password, PIN or OTP with anyone.';
    $('supportHoursText').textContent = config.support_hours || 'Every day, 10:00 AM - 10:00 PM';
    $('supportAverageReplyText').textContent = config.average_response_text || 'Average response time: within 24 hours.';
    const rows = [];
    if (config.email_enabled && config.support_email) rows.push(['email', 'Email', config.support_email, `mailto:${String(config.support_email).trim()}`]);
    if (config.whatsapp_enabled && config.whatsapp_number) rows.push(['chat', 'WhatsApp', config.whatsapp_number, `https://wa.me/${String(config.whatsapp_number).replace(/\D/g, '')}`]);
    if (config.call_enabled && config.support_phone) rows.push(['phone', 'Call', config.support_phone, `tel:${String(config.support_phone).replace(/[^+\d]/g, '')}`]);
    $('supportContactActions').innerHTML = rows.map(([type, label, detail, href]) =>
      `<a class="support-contact-action" href="${shell.escapeHtml(href)}"${href.startsWith('https:') ? ' target="_blank" rel="noopener noreferrer"' : ''}><span class="support-contact-action-icon ${type}" aria-hidden="true">${icon(type)}</span><strong>${shell.escapeHtml(label)}</strong><small>${shell.escapeHtml(detail)}</small></a>`
    ).join('');
  }

  init().catch((error) => shell.toast(error.message || 'Support is unavailable.', 'error'));
})();
