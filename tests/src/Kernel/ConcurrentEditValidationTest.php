<?php

declare(strict_types=1);

namespace Drupal\Tests\edw_mercury_editor\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
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
   * On a node form the checkbox and publishing info move into a collapsible.
   */
  public function testRestoreStatusFieldGroupsPublishingInfo(): void {
    // Weights as FormBuilder::doBuildForm() assigns them to NodeForm's
    // unweighted meta items by the time #after_build runs.
    $form = [
      'status' => ['#group' => 'status', '#weight' => 4],
      // A bundle outside any workflow: ModerationStateWidget::form() returns
      // [], so the display's component is an element with no widget.
      'moderation_state' => ['#weight' => 100],
      'meta' => [
        'published' => ['#weight' => 0, '#markup' => 'Published'],
        'changed' => ['#weight' => 0.001],
        'author' => ['#weight' => 0.002],
        'revision_information' => ['#weight' => 0.003],
      ],
    ];
    $form_state = new FormState();

    $result = edw_mercury_editor_restore_status_field($form, $form_state);

    $this->assertArrayNotHasKey('status', $result);
    $this->assertSame(['publishing', 'revision_information'], Element::children($result['meta'], TRUE));
    $this->assertArrayHasKey('moderation_state', $result);

    $publishing = $result['meta']['publishing'];
    $this->assertSame('details', $publishing['#type']);
    $this->assertSame('Revision information', (string) $publishing['#title']);
    $this->assertSame(['edw_mercury_editor/status_dropdown'], $publishing['#attached']['library']);
    $this->assertArrayNotHasKey('#group', $publishing['status']);
    $this->assertSame(['status', 'changed', 'author'], Element::children($publishing, TRUE));
  }

  /**
   * Gin's revision container joins the group and leaves meta's member list.
   */
  public function testRestoreStatusFieldMovesRevisionInformationIntoGroup(): void {
    $revision_information = [
      '#group' => 'meta',
      '#array_parents' => ['revision_information'],
      'revision' => ['#type' => 'checkbox'],
    ];
    $other_member = ['#group' => 'meta', '#array_parents' => ['something_else']];
    $form = [
      'status' => ['#group' => 'status'],
      'meta' => [
        'published' => ['#weight' => 0, '#markup' => 'Published'],
        'changed' => ['#weight' => 0.001],
      ],
      'revision_information' => $revision_information,
    ];
    $form_state = new FormState();
    // As processGroup() leaves it: members under integer keys, by reference
    // in core, next to the '#group_exists' flag.
    $form_state->setGroups(['meta' => ['#group_exists' => TRUE, $revision_information, $other_member]]);

    $result = edw_mercury_editor_restore_status_field($form, $form_state);

    $this->assertArrayNotHasKey('revision_information', $result);
    $publishing = $result['meta']['publishing'];
    $this->assertSame(['status', 'changed', 'revision_information'], Element::children($publishing, TRUE));
    $this->assertArrayNotHasKey('#group', $publishing['revision_information']);
    $this->assertSame(['#group_exists' => TRUE, 1 => $other_member], $form_state->getGroups()['meta']);
  }

  /**
   * On a moderated bundle the moderation widget leads the group.
   */
  public function testRestoreStatusFieldGroupsModerationWidget(): void {
    $form = [
      // content_moderation hides the checkbox; Gin has still grouped it.
      'status' => ['#group' => 'status', '#access' => FALSE],
      'moderation_state' => ['#weight' => 100, 'widget' => []],
      'meta' => [
        // content_moderation swaps this markup for the state label.
        'published' => ['#weight' => 0, '#markup' => 'Draft'],
        'changed' => ['#weight' => 0.001],
      ],
    ];

    $result = edw_mercury_editor_restore_status_field($form, new FormState());

    $this->assertArrayNotHasKey('moderation_state', $result);
    $publishing = $result['meta']['publishing'];
    $this->assertSame(['moderation_state', 'status', 'changed'], Element::children($publishing, TRUE));
  }

  /**
   * An inaccessible moderation widget stays put.
   */
  public function testRestoreStatusFieldIgnoresInaccessibleModerationWidget(): void {
    $form = [
      'status' => ['#group' => 'status'],
      'moderation_state' => ['#access' => FALSE, 'widget' => []],
      'meta' => [
        'published' => ['#markup' => 'Published'],
      ],
    ];

    $result = edw_mercury_editor_restore_status_field($form, new FormState());

    $this->assertArrayHasKey('moderation_state', $result);
    $this->assertArrayNotHasKey('moderation_state', $result['meta']['publishing']);
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
