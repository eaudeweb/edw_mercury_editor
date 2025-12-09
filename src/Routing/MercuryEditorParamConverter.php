<?php

namespace Drupal\edw_mercury_editor\Routing;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\mercury_editor\MercuryEditorTempstore;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Route;

/**
 * Overrides the Mercury Editor param converter.
 *
 * If a user has a stale private tempstore entry for an entity that has since
 * been saved via another route (e.g. the standard edit form), the base
 * converter would silently serve the old version — including any outdated
 * publish status. On read (GET) requests this override compares changed
 * timestamps and prefers the database when it is newer. It also returns NULL
 * for entities that have been deleted while a tempstore entry still exists.
 *
 * On write (e.g. the editor save POST) the tempstore entry is preserved even
 * when the database is newer, so that a concurrent edit can be detected and
 * reported as a conflict instead of being silently overwritten. The conflict
 * itself is surfaced by the validation handler registered in
 * edw_mercury_editor_form_alter().
 */
class MercuryEditorParamConverter implements ParamConverterInterface {

  public function __construct(
    protected MercuryEditorTempstore $tempstore,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityRepositoryInterface $entityRepository,
    protected RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    if (empty($defaults[$name])) {
      return NULL;
    }

    $uuid = $defaults[$name];
    $tempstore_entity = $this->tempstore->get($uuid);

    if ($tempstore_entity instanceof ContentEntityInterface) {
      // A new, not-yet-saved entity lives only in the tempstore (e.g. while
      // creating content). There is nothing to reconcile against the database,
      // so serve it directly — otherwise content creation is impossible.
      if ($tempstore_entity->isNew()) {
        return $tempstore_entity;
      }

      // We already know the entity type from the tempstore object, so we can
      // load the DB version directly without iterating all entity types.
      $db_entity = $this->entityRepository->loadEntityByUuid(
        $tempstore_entity->getEntityTypeId(),
        $uuid
      );

      if (!$db_entity) {
        // The entity was previously saved (it is not new) but no longer exists
        // in the database — it was deleted. Clear the stale tempstore entry and
        // 404.
        $this->tempstore->delete($tempstore_entity);
        return NULL;
      }

      if ($this->isReadRequest() && $this->isDbNewer($db_entity, $tempstore_entity)) {
        // On a read request the DB version is newer — the entity was saved
        // outside of this editor session (e.g. standard edit form, bulk action,
        // or another user). Discard the stale tempstore entry and use the fresh
        // DB version.
        //
        // On a write request the tempstore entry is kept even though the DB is
        // newer, so the save handler can detect the concurrent edit and report
        // a conflict rather than overwriting it.
        $this->tempstore->set($db_entity);
        return $db_entity;
      }

      return $tempstore_entity;
    }

    // No tempstore entry — iterate entity types to find the entity in the DB.
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type => $type_definition) {
      if (!method_exists($type_definition->getOriginalClass(), 'hasField')) {
        continue;
      }
      $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $entity_type);
      if (isset($field_definitions['uuid'])) {
        $entity = $this->entityRepository->loadEntityByUuid($entity_type, $uuid);
        if ($entity) {
          $this->tempstore->set($entity);
          return $entity;
        }
      }
    }

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route): bool {
    if (!empty($definition['type']) && $definition['type'] == 'mercury_editor_entity') {
      return TRUE;
    }
    if (isset($definition['mercury_editor_entity'])) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Returns TRUE for safe (cacheable) HTTP methods such as GET and HEAD.
   *
   * Write requests (e.g. the editor save POST) return FALSE so the tempstore
   * base is preserved for concurrent-edit detection.
   */
  private function isReadRequest(): bool {
    $request = $this->requestStack->getCurrentRequest();
    // Default to read semantics when there is no request (e.g. CLI/cron).
    return $request === NULL || $request->isMethodCacheable();
  }

  /**
   * Returns TRUE if the DB entity was saved more recently than the tempstore.
   */
  private function isDbNewer(EntityInterface $db_entity, EntityInterface $tempstore): bool {
    if (method_exists($db_entity, 'getChangedTime') && method_exists($tempstore, 'getChangedTime')) {
      return $db_entity->getChangedTime() > $tempstore->getChangedTime();
    }
    return FALSE;
  }

}
