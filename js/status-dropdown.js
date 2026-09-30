((Drupal, once) => {
  /**
   * The form fields a toolbar dropdown can stand in for, most specific first.
   *
   * A moderated bundle has the moderation state select, and content_moderation
   * hides the "Published" checkbox; any other bundle has only the checkbox.
   */
  const SOURCES = [
    {
      selector: 'select[name="moderation_state[0][state]"]',
      wrapper: '.field--name-moderation-state',
    },
    {
      selector: 'input[type="checkbox"][name="status[value]"]',
      wrapper: '.form-item',
    },
  ];

  /**
   * Finds the field the dropdown mirrors in the editor tray's form.
   *
   * @return {HTMLSelectElement|HTMLInputElement|null}
   *   The field, or NULL if the form has none (or doesn't grant access to it).
   */
  function findSource() {
    for (const source of SOURCES) {
      const field = document.querySelector(source.selector);
      if (field) {
        field.edwMeStatusWrapper = field.closest(source.wrapper);
        return field;
      }
    }
    return null;
  }

  /**
   * Lists the states the field offers.
   *
   * `saved` marks the state the entity was loaded with: the option the server
   * rendered as selected, or the checkbox's rendered checked state. The form
   * is re-rendered on every save, so this follows the entity.
   *
   * @param {HTMLSelectElement|HTMLInputElement} field
   *   The mirrored field.
   *
   * @return {Array<{value: string, label: string, saved: boolean}>}
   *   The states, in the field's order.
   */
  function statesOf(field) {
    if (field.tagName === 'SELECT') {
      const options = Array.from(field.options);
      const saved = options.find((option) => option.defaultSelected) || options[0];
      return options.map((option) => ({
        value: option.value,
        label: option.text,
        saved: option === saved,
      }));
    }
    return [
      { value: '1', label: Drupal.t('Published'), saved: field.defaultChecked },
      { value: '0', label: Drupal.t('Unpublished'), saved: !field.defaultChecked },
    ];
  }

  function valueOf(field) {
    if (field.tagName === 'SELECT') {
      return field.value;
    }
    return field.checked ? '1' : '0';
  }

  function setValue(field, value) {
    if (field.tagName === 'SELECT') {
      field.value = value;
    }
    else {
      field.checked = value === '1';
    }
    // Lets #states and anything else listening on the field react.
    field.dispatchEvent(new Event('change', { bubbles: true }));
  }

  /**
   * Builds the dropdown's (empty) markup once per page.
   *
   * The toolbar isn't part of the tray form, so the dropdown holds no form
   * input of its own: it only writes into the real field, which stays in the
   * form - hidden - and is submitted with it by the toolbar's "Save".
   *
   * @param {HTMLElement} toolbar
   *   The Mercury Editor toolbar.
   *
   * @return {HTMLElement}
   *   The dropdown's root element.
   */
  function build(toolbar) {
    const root = document.createElement('div');
    root.id = 'edw-me-status';
    root.className = 'edw-me-status';
    root.innerHTML = `
      <button type="button" class="me-button--secondary edw-me-status__toggle" aria-haspopup="true" aria-expanded="false" aria-controls="edw-me-status-menu">
        <span class="edw-me-status__label"></span>
      </button>
      <ul id="edw-me-status-menu" class="edw-me-status__menu" role="menu" hidden></ul>
    `;

    const toggle = root.querySelector('.edw-me-status__toggle');
    const menu = root.querySelector('.edw-me-status__menu');
    const items = () => Array.from(menu.querySelectorAll('[role="menuitemradio"]'));

    const close = (refocus) => {
      menu.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      if (refocus) {
        toggle.focus();
      }
    };
    const open = () => {
      menu.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      (items().find((item) => item.getAttribute('aria-checked') === 'true') || items()[0])?.focus();
    };

    toggle.addEventListener('click', () => (menu.hidden ? open() : close(false)));
    menu.addEventListener('click', (event) => {
      const item = event.target.closest('[role="menuitemradio"]');
      const field = findSource();
      if (item && field) {
        setValue(field, item.dataset.value);
        render(root);
        close(true);
      }
    });
    root.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !menu.hidden) {
        event.preventDefault();
        close(true);
      }
      else if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && !menu.hidden) {
        event.preventDefault();
        const list = items();
        const step = event.key === 'ArrowDown' ? 1 : -1;
        const index = list.indexOf(document.activeElement);
        list[(index + step + list.length) % list.length]?.focus();
      }
    });
    document.addEventListener('click', (event) => {
      if (!menu.hidden && !root.contains(event.target)) {
        close(false);
      }
    });

    // Left of "Save"; at the start of the group when the user can't save.
    const save = toolbar.querySelector('#me-save-btn');
    const group = toolbar.querySelector('.me-toolbar__edit-controls') || toolbar;
    if (save && save.parentElement === group) {
      group.insertBefore(root, save);
    }
    else {
      group.prepend(root);
    }
    return root;
  }

  /**
   * Refreshes the dropdown from the field in the tray's current form.
   *
   * @param {HTMLElement} root
   *   The dropdown's root element.
   */
  function render(root) {
    const field = findSource();
    root.hidden = !field;
    if (!field) {
      return;
    }
    // The field is only reachable from here now; the tray keeps the rest of
    // its group.
    field.edwMeStatusWrapper?.classList.add('edw-me-status-source');

    const states = statesOf(field);
    const value = valueOf(field);
    const current = states.find((state) => state.value === value) || states[0];
    const saved = states.find((state) => state.saved) || current;
    const pending = current !== saved;

    const toggle = root.querySelector('.edw-me-status__toggle');
    root.querySelector('.edw-me-status__label').textContent = current.label;
    root.classList.toggle('is-pending', pending);
    toggle.disabled = field.disabled;
    toggle.title = pending
      ? Drupal.t('Changes from @saved to @new when you save.', { '@saved': saved.label, '@new': current.label })
      : Drupal.t('@state. Pick a new state, then save.', { '@state': saved.label });

    const menu = root.querySelector('.edw-me-status__menu');
    menu.replaceChildren(...states.map((state) => {
      const li = document.createElement('li');
      li.setAttribute('role', 'none');
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'edw-me-status__option';
      item.setAttribute('role', 'menuitemradio');
      item.setAttribute('aria-checked', String(state === current));
      item.dataset.value = state.value;
      item.textContent = state.label;
      if (state.saved) {
        const note = document.createElement('span');
        note.className = 'edw-me-status__note';
        note.textContent = Drupal.t('current');
        item.append(' ', note);
      }
      li.append(item);
      return li;
    }));
  }

  /**
   * Puts the publishing/moderation state in the toolbar, left of "Save".
   *
   * It stays visible with the tray collapsed, and makes the state part of
   * the save decision. Re-rendered on every attach: the tray form is rebuilt
   * by AJAX on each save, with a new saved state and, for moderation, a new
   * set of allowed transitions.
   */
  Drupal.behaviors.edwMercuryEditorStatusDropdown = {
    attach() {
      const toolbar = document.getElementById('me-toolbar');
      if (!toolbar) {
        return;
      }
      once('edw-me-status', toolbar).forEach((element) => build(element));
      const root = document.getElementById('edw-me-status');
      if (root) {
        render(root);
      }
    },
  };
})(Drupal, once);
