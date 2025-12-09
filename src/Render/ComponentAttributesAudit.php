<?php

namespace Drupal\edw_mercury_editor\Render;

use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\layout_paragraphs\LayoutParagraphsLayout;

/**
 * Post-render callback that flags components missing their attributes.
 *
 * @see edw_mercury_editor_element_info_alter()
 */
class ComponentAttributesAudit implements TrustedCallbackInterface {

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['checkComponentAttributes'];
  }

  /**
   * Post-render callback: warns about components missing their attributes.
   *
   * @param string $children
   *   The rendered markup for the whole builder UI.
   * @param array $element
   *   The layout_paragraphs_builder render element, after #pre_render has
   *   populated #layout_paragraphs_layout.
   *
   * @return string
   *   The markup, prefixed with a warning banner when Twig debug is on and
   *   at least one component is affected.
   */
  public static function checkComponentAttributes($children, array $element) {
    $layout = $element['#layout_paragraphs_layout'] ?? NULL;
    if (!$layout instanceof LayoutParagraphsLayout) {
      return $children;
    }

    $offenders = [];
    foreach ($layout->getComponents() as $component) {
      $entity = $component->getEntity();
      if (!str_contains($children, 'data-uuid="' . $entity->uuid() . '"')) {
        $offenders[] = $entity;
      }
    }
    if (!$offenders) {
      return $children;
    }

    foreach ($offenders as $entity) {
      \Drupal::logger('edw_mercury_editor')->warning(
        'Paragraph %uuid (bundle %bundle) did not render its "attributes" variable. Its Twig template likely overrides the "paragraph" block without forwarding {{ attributes }} to whatever it includes, so Layout Paragraphs/Mercury Editor cannot track this component for drag-reorder, edit or save.',
        ['%uuid' => $entity->uuid(), '%bundle' => $entity->bundle()]
      );
    }

    if (!\Drupal::service('twig')->isDebug()) {
      return $children;
    }

    $labels = array_map(
      fn ($entity) => $entity->bundle() . ' (' . $entity->uuid() . ')',
      $offenders
    );
    $banner = '<div class="edw-mercury-editor-audit-warning">'
      . '⚠ Mercury Editor dev warning: component(s) not rendering Drupal attributes, may not save/reorder/edit correctly: '
      . htmlspecialchars(implode(', ', $labels), ENT_QUOTES)
      . '</div>';

    return $banner . $children;
  }

}
