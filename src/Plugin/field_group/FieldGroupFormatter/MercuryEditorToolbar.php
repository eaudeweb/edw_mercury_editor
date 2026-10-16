<?php

namespace Drupal\edw_mercury_editor\Plugin\field_group\FieldGroupFormatter;

use Drupal\field_group\Plugin\field_group\FieldGroupFormatter\Details;

/**
 * Puts a form display field group behind a Mercury Editor toolbar button.
 *
 * Renders a regular "details" element, marked for js/toolbar-groups.js. In
 * the Mercury Editor that script turns it into a popover opened from its own
 * toolbar button, left of "Save". The element never leaves the tray form, so
 * "Save" still submits its fields and their widgets' AJAX keeps working.
 *
 * Anywhere else (the standard edit form) the script isn't attached and the
 * group is just a details element.
 *
 * @FieldGroupFormatter(
 *   id = "edw_mercury_editor_toolbar",
 *   label = @Translation("Mercury Editor toolbar"),
 *   description = @Translation("In the Mercury Editor, a toolbar button that opens a panel with the group's fields. Elsewhere, a details element."),
 *   supported_contexts = {
 *     "form"
 *   }
 * )
 */
class MercuryEditorToolbar extends Details {

  /**
   * {@inheritdoc}
   */
  public function process(&$element, $processed_object) {
    parent::process($element, $processed_object);
    $element['#attributes']['class'][] = 'edw-me-toolbar-group';
    $element['#attributes']['data-edw-me-toolbar-group'] = $this->group->group_name;
    // The button's text. Read from here rather than the summary, which the
    // theme may decorate (required marker, error count).
    $element['#attributes']['data-edw-me-toolbar-label'] = $this->getLabel();
  }

}
