((Drupal, drupalSettings, once) => {
  /**
   * Replaces the Mercury Editor logo in the toolbar with a "View" button.
   *
   * Unlike "Done" (which navigates away and discards any tray edits that were
   * never saved through the toolbar "Save" button), this opens the entity's
   * live canonical page in a new tab, so editors can check the published
   * state without losing an in-progress editing session.
   */
  Drupal.behaviors.edwMercuryEditorViewButton = {
    attach(context) {
      const viewUrl = (drupalSettings.edwMercuryEditor || {}).viewUrl;
      if (!viewUrl) {
        return;
      }
      once('edw-me-view-btn', '#me-toolbar .me-toolbar__logo', context).forEach((logo) => {
        const link = document.createElement('a');
        link.id = 'me-view-btn';
        link.className = 'me-button--secondary';
        link.href = viewUrl;
        link.target = '_blank';
        link.rel = 'noopener';
        link.title = Drupal.t('View the live page in a new tab.');
        link.textContent = Drupal.t('View');
        logo.replaceWith(link);
      });
    },
  };
})(Drupal, drupalSettings, once);
