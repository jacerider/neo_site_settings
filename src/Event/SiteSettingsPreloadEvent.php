<?php

namespace Drupal\neo_site_settings\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event that is fired before a settings type is loaded.
 *
 * Allows for altering of the type id.
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
