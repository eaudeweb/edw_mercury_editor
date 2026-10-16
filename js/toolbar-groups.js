((Drupal, once) => {
  /**
   * The field groups a form display puts in the toolbar.
   *
   * Rendered by the "Mercury Editor toolbar" field group format.
   *
   * @see \Drupal\edw_mercury_editor\Plugin\field_group\FieldGroupFormatter\MercuryEditorToolbar
   */
  const PANEL = 'details[data-edw-me-toolbar-group]';

  /**
   * Gap between a toolbar button and its panel, and the viewport edges.
   */
  const GAP = 4;

  function buttonFor(toolbar, name) {
    return Array.from(toolbar.querySelectorAll('[data-edw-me-toolbar-button]'))
      .find((button) => button.dataset.edwMeToolbarButton === name) || null;
  }

  /**
   * Creates a group's toolbar button.
   *
   * Placed left of the state dropdown, or else of "Save", or at the start of
   * the group when the user can't save. Called in form order, so the buttons
   * keep the form display's order.
   *
   * @param {HTMLElement} toolbar
   *   The Mercury Editor toolbar.
   * @param {string} name
   *   The field group's machine name.
   *
   * @return {HTMLButtonElement}
   *   The button.
   */
  function build(toolbar, name) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'me-button--secondary edw-me-toolbar-toggle';
    button.dataset.edwMeToolbarButton = name;
    button.innerHTML = '<span class="edw-me-toolbar-toggle__label"></span>';

    const group = toolbar.querySelector('.me-toolbar__edit-controls') || toolbar;
    const anchor = group.querySelector(':scope > #edw-me-status')
      || group.querySelector(':scope > #me-save-btn');
    if (anchor) {
      group.insertBefore(button, anchor);
    }
    else {
      group.prepend(button);
    }
    return button;
  }

  /**
   * Puts a panel under its button, right-aligned, inside the viewport.
   */
  function position(panel, button) {
    const rect = button.getBoundingClientRect();
    panel.style.top = `${rect.bottom + GAP}px`;
    panel.style.right = `${Math.max(GAP, window.innerWidth - rect.right)}px`;
    panel.style.maxHeight = `${window.innerHeight - rect.bottom - 2 * GAP}px`;
  }

  /**
   * Turns a field group into a popover opened from its toolbar button.
   *
   * The group stays where it is in the tray form, so "Save" submits its
   * fields and widget AJAX inside it keeps working; only the popover makes it
   * show over the toolbar (and with the tray collapsed).
   *
   * @param {HTMLDetailsElement} panel
   *   The field group.
   * @param {HTMLElement} toolbar
   *   The Mercury Editor toolbar.
   */
  function attachPanel(panel, toolbar) {
    const name = panel.dataset.edwMeToolbarGroup;
    const button = buttonFor(toolbar, name) || build(toolbar, name);

    if (!panel.id) {
      panel.id = `edw-me-toolbar-group-${name}`;
    }
    panel.open = true;
    panel.popover = 'auto';
    // 'ui-front' makes jQuery UI widgets (e.g. the entity autocomplete menu)
    // append their popups in here rather than to <body>: under the top layer
    // they would be hidden, and clicking them would light-dismiss the panel.
    panel.classList.add('is-popover', 'ui-front');
    // Widgets created before this ran already picked their container.
    if (window.jQuery?.ui?.autocomplete) {
      window.jQuery(panel).find('.ui-autocomplete-input').autocomplete('option', 'appendTo', panel);
    }
    button.popoverTargetElement = panel;
    button.setAttribute('aria-controls', panel.id);

    button.querySelector('.edw-me-toolbar-toggle__label').textContent = panel.dataset.edwMeToolbarLabel;
    button.classList.remove('is-open', 'is-pending');
    // A server-side error on a field only reachable from here.
    button.classList.toggle('is-error', !!panel.querySelector('.error, [aria-invalid="true"]'));

    panel.addEventListener('beforetoggle', (event) => {
      if (event.newState === 'open') {
        position(panel, button);
      }
      button.classList.toggle('is-open', event.newState === 'open');
    });
    panel.addEventListener('change', () => button.classList.add('is-pending'));
    // A <summary> would collapse the panel's content, not close the panel.
    panel.querySelector(':scope > summary')?.addEventListener('click', (event) => event.preventDefault());
  }

  /**
   * Drops the buttons of groups the rebuilt form no longer has.
   */
  function prune(toolbar) {
    toolbar.querySelectorAll('[data-edw-me-toolbar-button]').forEach((button) => {
      if (!button.popoverTargetElement?.isConnected) {
        button.remove();
      }
    });
  }

  /**
   * Opens the panel holding the first invalid field when "Save" is clicked.
   *
   * Mercury Editor's "Save" only reports invalid fields that are visible, and
   * submits otherwise: a required field left empty in a closed panel would be
   * caught only by the server, after a round trip. Runs in the capture phase,
   * so the panel is open by the time Mercury Editor's click handler looks.
   *
   * @see mercury_editor/source/js/edit-screen.js save()
   */
  function revealInvalid() {
    const invalid = Array.from(document.querySelectorAll(`${PANEL} :is(input, textarea, select)`))
      .find((input) => !input.disabled && !input.validity.valid);
    const panel = invalid?.closest(PANEL);
    if (panel && panel.popover && !panel.matches(':popover-open')) {
      panel.showPopover();
    }
  }

  /**
   * Puts the "Mercury Editor toolbar" field groups in the toolbar.
   *
   * Runs on every attach: the tray form is replaced by AJAX on each save,
   * which brings new group elements for the existing buttons.
   */
  Drupal.behaviors.edwMercuryEditorToolbarGroups = {
    attach(context) {
      const toolbar = document.getElementById('me-toolbar');
      if (!toolbar) {
        return;
      }
      once('edw-me-toolbar-group', PANEL, context).forEach((panel) => attachPanel(panel, toolbar));
      prune(toolbar);

      once('edw-me-toolbar-groups', toolbar).forEach(() => {
        document.getElementById('me-save-btn')?.addEventListener('click', revealInvalid, true);
        window.addEventListener('resize', () => {
          toolbar.querySelectorAll('[data-edw-me-toolbar-button]').forEach((button) => {
            const panel = button.popoverTargetElement;
            if (panel?.matches(':popover-open')) {
              position(panel, button);
            }
          });
        });
      });
    },
  };
})(Drupal, once);
