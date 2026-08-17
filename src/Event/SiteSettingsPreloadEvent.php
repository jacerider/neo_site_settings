<?php

namespace Drupal\neo_site_settings\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event that is fired before a settings type is loaded.
 *
 * Allows for altering of the type id, so that a subscriber can serve a
 * context-specific settings entity (per domain, per language, and so on).
 *
 * Two things subscribers must know:
 *
 * - Only SiteSettingsStorage::loadByType() and loadOrCreateByType() consult
 *   this event. Core's load(), loadMultiple(), loadByProperties() and entity
 *   queries do not, so a remapped id is visible through the first pair and
 *   invisible through the second. Code that must honour the remap has to go
 *   through the byType methods.
 * - Subscribers must be idempotent and free of side effects. The value is used
 *   both to look a row up and, on a miss, to create one, and bundle teardown
 *   deletes by bundle rather than by the remapped id.
 */
class SiteSettingsPreloadEvent extends Event {

  const EVENT_NAME = 'site_settings_preload';

  /**
   * The type id.
   *
   * @var string
   */
  public $typeId;

  /**
   * Constructs the object.
   *
   * @param string $type_id
   *   The type id about to be loaded.
   */
  public function __construct($type_id) {
    $this->typeId = $type_id;
  }

  /**
   * Return the type id.
   *
   * @return string
   *   The type id.
   */
  public function getTypeId() {
    return $this->typeId;
  }

  /**
   * Set the type id.
   *
   * @return $this
   */
  public function setTypeId($type_id) {
    $this->typeId = $type_id;
    return $this;
  }

}
