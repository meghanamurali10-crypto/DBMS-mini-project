// ─────────────────────────────────────────────────────────────
// 1. REQUEST FORM: enable/disable qty + justification on check
// ─────────────────────────────────────────────────────────────
document.addEventListener('change', (event) => {
  if (event.target.matches('.request-check')) {
    const row = event.target.closest('tr');
    const qty = row.querySelector('.request-qty');
    const justification = row.querySelector('.request-justification');
    qty.disabled = !event.target.checked;
    if (justification) justification.disabled = !event.target.checked;
    if (event.target.checked && Number(qty.value) < 1) qty.value = 1;
  }
});

// ─────────────────────────────────────────────────────────────
// 2. TABLE SEARCH (data-filter-table attribute)
// ─────────────────────────────────────────────────────────────
document.addEventListener('input', (event) => {
  if (event.target.matches('[data-filter-table]')) {
    const term = event.target.value.toLowerCase();
    const tableId = event.target.dataset.filterTable;
    if (!tableId) return;
    document.querySelectorAll(tableId + ' tbody tr').forEach(row => {
      row.hidden = !row.textContent.toLowerCase().includes(term);
    });
  }
});

// ─────────────────────────────────────────────────────────────
// 3. GSSSR DECISION MODAL: highlight preferred button
// ─────────────────────────────────────────────────────────────
document.addEventListener('click', (event) => {
  const trigger = event.target.closest('.gsssr-decision-trigger');
  if (!trigger) return;

  const targetSelector = trigger.getAttribute('data-bs-target');
  const mode = trigger.dataset.decisionMode || 'approve';
  const modal = targetSelector ? document.querySelector(targetSelector) : null;
  if (!modal) return;

  const preferred = modal.querySelector('input[name="preferred_decision"]');
  if (preferred) preferred.value = mode;

  modal.querySelectorAll('.gsssr-decision-btn').forEach(btn => {
    btn.classList.toggle('is-preferred', btn.value === mode);
  });
});

// ─────────────────────────────────────────────────────────────
// 4. DASHBOARD: toggle year vs custom date range fields
// ─────────────────────────────────────────────────────────────
document.addEventListener('change', (event) => {
  const filterSelect = event.target.closest('.graph-filter-mode');
  if (!filterSelect) return;

  const form = filterSelect.closest('form');
  if (!form) return;

  const yearFields = form.querySelectorAll('.graph-year-field');
  const dateFields = form.querySelectorAll('.graph-date-field');

  if (filterSelect.value === 'custom') {
    yearFields.forEach(el => el.style.display = 'none');
    dateFields.forEach(el => el.style.display = 'block');
  } else {
    yearFields.forEach(el => el.style.display = 'block');
    dateFields.forEach(el => el.style.display = 'none');
  }
});

// Set initial visibility on page load
window.addEventListener('DOMContentLoaded', () => {
  const filterSelect = document.querySelector('.graph-filter-mode');
  if (filterSelect) filterSelect.dispatchEvent(new Event('change'));
});

// ─────────────────────────────────────────────────────────────
// 5. AUTO-GENERATE ITEM CODE (Add Item modal)
//    Triggered when the category <select data-autocode-target>
//    changes. Calls /api/get_next_item_code.php and fills the
//    item code input with the next incremented code.
// ─────────────────────────────────────────────────────────────
document.addEventListener('change', (event) => {
  if (!event.target.matches('[data-autocode-target]')) return;

  const catId     = event.target.value;
  const targetId  = event.target.dataset.autocodeTarget;   // e.g. "item_code_field_new"
  const spinnerId = event.target.dataset.autocodeSpinner;  // e.g. "item_code_spinner"

  const codeInput = document.getElementById(targetId);
  const spinner   = spinnerId ? document.getElementById(spinnerId) : null;

  // Clear the code field if "-- select --" is chosen
  if (!catId) {
    if (codeInput) {
      codeInput.value = '';
      codeInput.classList.remove('is-valid', 'is-invalid');
      codeInput.placeholder = 'Select a category first';
    }
    return;
  }

  if (!codeInput) return;

  // Show spinner, disable input while loading
  if (spinner) spinner.style.display = '';
  codeInput.disabled  = true;
  codeInput.classList.remove('is-valid', 'is-invalid');
  codeInput.value = '';
  codeInput.placeholder = 'Generating…';

  const url = BASE_URL + '/api/get_next_item_code.php?category_id=' + encodeURIComponent(catId);

  fetch(url)
    .then(response => {
      if (!response.ok) throw new Error('HTTP ' + response.status);
      return response.json();
    })
    .then(data => {
      if (data.code) {
        codeInput.value       = data.code;
        codeInput.placeholder = '';
        codeInput.classList.add('is-valid');
        // Remove green highlight after 2 s so user can see it was set
        setTimeout(() => codeInput.classList.remove('is-valid'), 2000);
      } else {
        // API returned empty code (no prefix could be built)
        codeInput.placeholder = 'Enter code manually';
        codeInput.classList.add('is-invalid');
        setTimeout(() => codeInput.classList.remove('is-invalid'), 3000);
        if (data.error) console.warn('Auto-code error:', data.error);
      }
    })
    .catch(err => {
      console.error('Auto-code fetch failed:', err);
      codeInput.placeholder = 'Enter code manually';
      codeInput.classList.add('is-invalid');
      setTimeout(() => codeInput.classList.remove('is-invalid'), 3000);
    })
    .finally(() => {
      // Re-enable input and hide spinner regardless of outcome
      codeInput.disabled = false;
      if (spinner) spinner.style.display = 'none';
      codeInput.focus();
    });
});