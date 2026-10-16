'use strict';

const menuButton = document.querySelector('.menu-toggle');
const navigation = document.querySelector('.main-nav');
if (menuButton && navigation) {
  menuButton.addEventListener('click', () => {
    const isOpen = navigation.classList.toggle('is-open');
    menuButton.setAttribute('aria-expanded', String(isOpen));
    menuButton.setAttribute('aria-label', isOpen ? 'Fechar menu' : 'Abrir menu');
  });
  navigation.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', () => {
      navigation.classList.remove('is-open');
      menuButton.setAttribute('aria-expanded', 'false');
      menuButton.setAttribute('aria-label', 'Abrir menu');
    });
  });
}

const year = document.getElementById('current-year');
if (year) year.textContent = String(new Date().getFullYear());

// Confirma operações com efeito persistente antes de enviar o formulário.
document.querySelectorAll('form [data-confirm]').forEach((control) => {
  control.closest('form')?.addEventListener('submit', (event) => {
    const message = control.getAttribute('data-confirm');
    if (message && !window.confirm(message)) event.preventDefault();
  });
});

// Evita submissões repetidas acidentais, mantendo o formulário interactivo se houver erro de validação.
document.querySelectorAll('form').forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;
    const submitter = form.querySelector('button[type="submit"]:not([form])');
    if (submitter && form.checkValidity()) {
      submitter.dataset.originalText = submitter.textContent || '';
      submitter.disabled = true;
      submitter.textContent = 'A processar…';
    }
  });
});

// Mostra apenas estados válidos para o tipo de anúncio seleccionado no painel administrativo.
const moderationForm = document.querySelector('.admin-moderation-form');
if (moderationForm) {
  const entitySelect = moderationForm.querySelector('select[name="entity_type"]');
  const statusSelect = moderationForm.querySelector('select[name="status"]');
  const filterStatuses = () => {
    if (!entitySelect || !statusSelect) return;
    let firstVisible = null;
    statusSelect.querySelectorAll('option[data-for]').forEach((option) => {
      const visible = (option.dataset.for || '').split(',').includes(entitySelect.value);
      option.hidden = !visible;
      option.disabled = !visible;
      if (visible && !firstVisible) firstVisible = option;
    });
    if (statusSelect.selectedOptions.length && statusSelect.selectedOptions[0].disabled && firstVisible) {
      statusSelect.value = firstVisible.value;
    }
  };
  entitySelect?.addEventListener('change', filterStatuses);
  filterStatuses();
}
