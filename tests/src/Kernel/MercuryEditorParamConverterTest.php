<?php

declare(strict_types=1);

namespace Drupal\Tests\edw_mercury_editor\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\edw_mercury_editor\Routing\MercuryEditorParamConverter;
use Drupal\mercury_editor\MercuryEditorTempstore;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Tests the Mercury Editor param converter override.
 *
 * The base mercury_editor converter always trusts whatever is in the private
 * tempstore. This override reconciles it against the database so a stale
 * tempstore entry can't silently resurrect a deleted entity or serve an
 * outdated version, while still preserving the conflict on a save request so
 * it can be reported instead of silently overwritten.
 *
 * @see \Drupal\edw_mercury_editor\Routing\MercuryEditorParamConverter
 */
#[Group('edw_mercury_editor')]
#[RunTestsInSeparateProcesses]
class MercuryEditorParamConverterTest extends KernelTestBase {

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
   * The Mercury Editor tempstore repository.
   */
  protected MercuryEditorTempstore $tempstore;

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
    $this->tempstore = $this->container->get('mercury_editor.tempstore_repository');
  }

  /**
   * Builds a converter wired to a request of the given HTTP method.
   */
  protected function converterForMethod(string $method): MercuryEditorParamConverter {
    $request_stack = new RequestStack();
    $request_stack->push(Request::create('/mercury-editor/test', $method));
    return new MercuryEditorParamConverter(
      $this->tempstore,
      $this->container->get('entity_type.manager'),
      $this->container->get('entity_field.manager'),
      $this->container->get('entity.repository'),
      $request_stack,
    );
  }

  /**
   * Runs convert() for a node uuid, the way the routing system would.
   */
  protected function convert(MercuryEditorParamConverter $converter, string $uuid) {
    return $converter->convert($uuid, ['type' => 'mercury_editor_entity'], 'mercury_editor_entity', ['mercury_editor_entity' => $uuid]);
  }

  /**
   * The container swaps the base converter for this override.
   */
  public function testServiceIsOverridden(): void {
    $this->assertInstanceOf(
      MercuryEditorParamConverter::class,
      $this->container->get('mercury_editor.param_converter')
    );
  }

  /**
   * A new, unsaved entity only lives in the tempstore.
   */
  public function testNewUnsavedEntityIsServedFromTempstore(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Draft', 'uid' => 1]);
    $this->tempstore->set($node);

    $result = $this->convert($this->converterForMethod('GET'), $node->uuid());

    // The tempstore round-trips through serialization, so the result is a
    // distinct but equivalent object, not the same PHP instance.
    $this->assertTrue($result->isNew());
    $this->assertEquals($node->uuid(), $result->uuid());
    $this->assertEquals('Draft', $result->label());
  }

  /**
   * On a read request, a newer DB version replaces a stale tempstore entry.
   */
  public function testReadRequestDiscardsStaleTempstoreEntry(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Original', 'uid' => 1]);
    $node->save();
    $uuid = $node->uuid();

    // The editing session's base version, captured before another save.
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $base = $storage->loadUnchanged($node->id());
    $base->setChangedTime($node->getChangedTime() - 100);
    $this->tempstore->set($base);

    // Someone else saves a newer version outside of this editing session.
    $node->setChangedTime($node->getChangedTime() + 100);
    $node->save();

    $result = $this->convert($this->converterForMethod('GET'), $uuid);

    $this->assertEquals($node->getChangedTime(), $result->getChangedTime());
    // The stale entry is replaced, not just shadowed.
    $this->assertEquals($node->getChangedTime(), $this->tempstore->get($uuid)->getChangedTime());
  }

  /**
   * On a write request, the stale tempstore entry survives so the validate
   * handler can detect and report the conflict.
   */
  public function testWriteRequestPreservesStaleTempstoreEntryForConflictDetection(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Original', 'uid' => 1]);
    $node->save();
    $uuid = $node->uuid();

    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $base = $storage->loadUnchanged($node->id());
    $base->setChangedTime($node->getChangedTime() - 100);
    $this->tempstore->set($base);

    $node->setChangedTime($node->getChangedTime() + 100);
    $node->save();

    $result = $this->convert($this->converterForMethod('POST'), $uuid);

    $this->assertEquals($base->getChangedTime(), $result->getChangedTime());
  }

  /**
   * No conflict, no reconciliation: the tempstore entry is returned as-is.
   */
  public function testTempstoreEntryReturnedWhenNotStale(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Original', 'uid' => 1]);
    $node->save();
    $uuid = $node->uuid();
    $this->tempstore->set($node);

    $result = $this->convert($this->converterForMethod('GET'), $uuid);

    $this->assertEquals($node->getChangedTime(), $result->getChangedTime());
  }

  /**
   * An entity deleted while a tempstore entry still points at it 404s.
   */
  public function testDeletedEntityClearsTempstoreAndReturnsNull(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Temporary', 'uid' => 1]);
    $node->save();
    $uuid = $node->uuid();
    $this->tempstore->set($node);
    $node->delete();

    $result = $this->convert($this->converterForMethod('GET'), $uuid);

    $this->assertNull($result);
    $this->assertNull($this->tempstore->get($uuid));
  }

  /**
   * With no tempstore entry at all, the entity is loaded from the DB and
   * seeded into the tempstore.
   */
  public function testNoTempstoreEntryLoadsFromDatabase(): void {
    $node = Node::create(['type' => 'page', 'title' => 'Original', 'uid' => 1]);
    $node->save();
    $uuid = $node->uuid();

    $result = $this->convert($this->converterForMethod('GET'), $uuid);

    $this->assertEquals($node->id(), $result->id());
    $this->assertNotNull($this->tempstore->get($uuid));
  }

}
