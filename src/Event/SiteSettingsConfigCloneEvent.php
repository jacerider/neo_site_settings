<?php

namespace Drupal\neo_site_settings\Event;

use Drupal\field\FieldConfigInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event that is fired before a config value is cloned to another config object.
 *
 * Allows for altering of config clone location.
 */
class SiteSettingsConfigCloneEvent extends Event {

  const EVENT_NAME = 'site_settings_config_clone';

  /**
   * The field config.
   *
   * @var \Drupal\field\FieldConfigInterface
   */
  public $fieldConfig;

  /**
   * The config name.
   *
   * @var string
   */
  public $name;

  /**
   * The config key.
   *
   * @var string
   */
  public $key;

  /**
   * The config delimiter delimiter.
   *
   * @var string
   */
  public $delimiter;

  /**
   * Constructs the object.
   *
   * @param \Drupal\field\FieldConfigInterface $field_config
   *   The field config.
   * @param string $name
   *   The config name about to be cloned to.
   * @param string $key
   *   The config property key that the value will be cloned to.
   * @param string $delimiter
   *   If supplied, the config value will be joined and stored as single
   *   value.
   */
  public function __construct(FieldConfigInterface $field_config, $name, $key, $delimiter = NULL) {
    $this->fieldConfig = $field_config;
    $this->name = $name;
    $this->key = $key;
    $this->delimiter = $delimiter;
  }

  /**
   * Return the field config.
   *
   * @return \Drupal\field\FieldConfigInterface
   *   The field config.
   */
  public function getFieldConfig() {
    return $this->fieldConfig;
  }

  /**
   * Return the config name.
   *
   * @return string
   *   The config name.
   */
  public function getName() {
    return $this->name;
  }

  /**
   * Set the config name.
   *
   * @return $this
   */
  public function setName($name) {
    $this->name = $name;
    return $this;
  }

  /**
   * Return the config key.
   *
   * @return string
   *   The config key.
   */
  public function getKey() {
    return $this->key;
  }

  /**
   * Set the config key.
   *
   * @return $this
   */
  public function setKey($key) {
    $this->key = $key;
    return $this;
  }

  /**
   * Return the config delimiter.
   *
   * @return string
   *   The config delimiter.
   */
  public function getDelimiter() {
    return $this->delimiter;
  }

  /**
   * Check if config name and config key are null.
   *
   * @return bool
   *   Returns TRUE if either name or key is empty.
   */
  public function isEmpty() {
    return empty($this->getName()) || empty($this->getKey());
  }

}
