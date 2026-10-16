<?php

declare(strict_types=1);

namespace Drupal\Tests\edw_mercury_editor\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that widgets stay inside "Mercury Editor toolbar" field groups.
 *
 * @see edw_mercury_editor_field_widget_single_element_form_alter()
 */
#[Group('edw_mercury_editor')]
#[RunTestsInSeparateProcesses]
class ToolbarFieldGroupWidgetTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'edw_mercury_editor',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Runs the hook on the title widget, with the given field groups.
   *
   * The title stands in for any field whose widget sets its own '#group'
   * (like PathWidget's 'advanced').
   *
   * @return array
   *   The altered widget element.
   */
  protected function alterTitleWidget(array $groups): array {
    $display = EntityFormDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    foreach ($groups as $name => $group) {
      $display->setThirdPartySetting('field_group', $name, $group + ['parent_name' => '']);
    }
    $form_state = new FormState();
    $form_state->set('form_display', $display);
    $node = Node::create(['type' => 'page', 'title' => 'Page']);

    $element = ['#group' => 'advanced'];
    edw_mercury_editor_field_widget_single_element_form_alter($element, $form_state, ['items' => $node->get('title')]);
    return $element;
  }

  /**
   * A field in a toolbar group loses the widget's own grouping.
   */
  public function testWidgetInToolbarGroupStaysInIt(): void {
    $element = $this->alterTitleWidget([
      'group_publishing' => ['children' => ['title'], 'format_type' => 'edw_mercury_editor_toolbar'],
    ]);
    $this->assertArrayNotHasKey('#group', $element);
  }

  /**
   * So does one nested in another group inside a toolbar group.
   */
  public function testWidgetNestedInToolbarGroupStaysInIt(): void {
    $element = $this->alterTitleWidget([
      'group_publishing' => ['children' => ['group_inner'], 'format_type' => 'edw_mercury_editor_toolbar'],
      'group_inner' => ['children' => ['title'], 'format_type' => 'details', 'parent_name' => 'group_publishing'],
    ]);
    $this->assertArrayNotHasKey('#group', $element);
  }

  /**
   * A field in a sidebar group stays in that group's accordion.
   */
  public function testWidgetInSidebarGroupStaysInIt(): void {
    $element = $this->alterTitleWidget([
      'group_publishing' => ['children' => ['title'], 'format_type' => 'details_sidebar'],
    ]);
    $this->assertArrayNotHasKey('#group', $element);
  }

  /**
   * Other field groups keep field_group's usual behavior.
   */
  public function testWidgetInOtherGroupIsLeftAlone(): void {
    $element = $this->alterTitleWidget([
      'group_banner' => ['children' => ['title'], 'format_type' => 'details'],
    ]);
    $this->assertSame('advanced', $element['#group']);
  }

}
