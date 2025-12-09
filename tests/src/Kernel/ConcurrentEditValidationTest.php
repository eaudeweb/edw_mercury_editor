<?php

declare(strict_types=1);

namespace Drupal\Tests\edw_mercury_editor\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests concurrent-edit detection on Mercury Editor entity forms.
 *
 * Mercury Editor forces the entity's "changed" timestamp to the current time
 * on every form validate (MercuryEditorEntityFormTrait::validateForm), which
 * bypasses Drupal core's own EntityChanged optimistic-lock constraint. These
 * tests exercise the replacement validation this module registers in
 * edw_mercury_editor_form_alter(), which compares the tempstore's base
 * version against the live database row instead.
 *
 * @see edw_mercury_editor_form_alter()
 * @see edw_mercury_editor_concurrent_edit_validate()
 */
#[Group('edw_mercury_editor')]
#[RunTestsInSeparateProcesses]
class ConcurrentEditValidationTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'file',
    'node',
    'entity_reference_revisions',
    'paragraphs',
    'layout_discovery',
    'layout_paragraphs',
    'style_options',
    'mercury_editor',
    'edw_mercury_editor',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('file');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['field', 'node', 'filter']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Builds a real Mercury Editor form object for the given node.
   *
   * mercury_editor_entity_type_build() registers MercuryEditorNodeForm as
   * node's 'mercury_editor' operation, so this returns the actual class
   * edw_mercury_editor_form_alter() sees in production, fully wired by the
   * container (tempstore, request stack, etc.) rather than a hand-rolled
   * stub.
   */
  protected function mercuryEditorFormObject(Node $node) {
    $form_object = $this->container->get('entity_type.manager')->getFormObject('node', 'mercury_editor');
    $form_object->setEntity($node);
    return $form_object;
  }

  /**
   * A save is blocked when the database changed since the session began.
   */
  public function testConcurrentEditIsBlocked(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Original', 'uid' => 1]);
    $node->save();

    // The tempstore holds the version this editing session started from.
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $base = $storage->loadUnchanged($node->id());
    $this->container->get('mercury_editor.tempstore_repository')->set($base);

    // Someone else saves a newer version outside of this editing session.
    $node->setChangedTime($node->getChangedTime() + 100);
    $node->save();

    $form_state = new FormState();
    $form_state->setFormObject($this->mercuryEditorFormObject($base));
    $form = ['#parents' => []];

    edw_mercury_editor_concurrent_edit_validate($form, $form_state);

    $this->assertTrue($form_state->hasAnyErrors());
    $errors = $form_state->getErrors();
    $this->assertStringContainsString('updated by another user', (string) reset($errors));
  }

  /**
   * No conflict, no error: nobody else saved since the session began.
   */
  public function testNoConflictWhenNobodyElseSaved(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Original', 'uid' => 1]);
    $node->save();

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $base = $storage->loadUnchanged($node->id());
    $this->container->get('mercury_editor.tempstore_repository')->set($base);

    $form_state = new FormState();
    $form_state->setFormObject($this->mercuryEditorFormObject($base));
    $form = ['#parents' => []];

    edw_mercury_editor_concurrent_edit_validate($form, $form_state);

    $this->assertFalse($form_state->hasAnyErrors());
  }

  /**
   * A brand-new, not-yet-saved entity has nothing in the DB to conflict with.
   */
  public function testNewEntityIsNeverBlocked(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Unsaved', 'uid' => 1]);

    $form_state = new FormState();
    $form_state->setFormObject($this->mercuryEditorFormObject($node));
    $form = ['#parents' => []];

    edw_mercury_editor_concurrent_edit_validate($form, $form_state);

    $this->assertFalse($form_state->hasAnyErrors());
  }

  /**
   * With no tempstore base to compare against, validation is a no-op.
   */
  public function testNoTempstoreBaseIsANoOp(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Original', 'uid' => 1]);
    $node->save();

    $form_state = new FormState();
    $form_state->setFormObject($this->mercuryEditorFormObject($node));
    $form = ['#parents' => []];

    edw_mercury_editor_concurrent_edit_validate($form, $form_state);

    $this->assertFalse($form_state->hasAnyErrors());
  }

  /**
   * hook_form_alter() wires up both callbacks, only on the editor's own form.
   */
  public function testFormAlterWiresUpValidationOnlyForMercuryEditorOperation(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Original', 'uid' => 1]);
    $node->save();

    $form_state = new FormState();
    $form_state->setFormObject($this->mercuryEditorFormObject($node));
    $form = [];
    edw_mercury_editor_form_alter($form, $form_state, 'node_page_mercury_editor_form');

    $this->assertContains('edw_mercury_editor_concurrent_edit_validate', $form['#validate']);
    $this->assertContains('edw_mercury_editor_restore_status_field', $form['#after_build']);

    // The standard node edit form (operation 'default') is untouched.
    $default_form_object = $this->container->get('entity_type.manager')->getFormObject('node', 'default');
    $default_form_object->setEntity($node);
    $default_form_state = new FormState();
    $default_form_state->setFormObject($default_form_object);
    $default_form = [];
    edw_mercury_editor_form_alter($default_form, $default_form_state, 'node_page_edit_form');

    $this->assertArrayNotHasKey('#validate', $default_form);
    $this->assertArrayNotHasKey('#after_build', $default_form);
  }

  /**
   * Gin's "status" field group is unset so the checkbox renders in the tray.
   */
  public function testRestoreStatusFieldUndoesGinGrouping(): void {
    $form = ['status' => ['#group' => 'status']];
    $form_state = new FormState();

    $result = edw_mercury_editor_restore_status_field($form, $form_state);

    $this->assertArrayNotHasKey('#group', $result['status']);
  }

  /**
   * Any other grouping (or none) is left alone.
   */
  public function testRestoreStatusFieldLeavesOtherGroupingAlone(): void {
    $form = ['status' => ['#group' => 'some_other_group']];
    $form_state = new FormState();

    $result = edw_mercury_editor_restore_status_field($form, $form_state);

    $this->assertSame('some_other_group', $result['status']['#group']);
  }

}
