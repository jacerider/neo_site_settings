<?php

namespace Drupal\neo_site_settings;

use Drupal\Core\Config\Entity\ConfigEntityInterface;

/**
 * Provides an interface for defining config page type entities.
 */
interface SiteSettingsTypeInterface extends ConfigEntityInterface {

  /**
   * Gets the settings type weight.
   *
   * @return int
   *   The settings type weight.
   */
  public function getWeight(): int;

  /**
   * Sets the settings type weight.
   *
   * @param int $weight
   *   The settings type weight.
   *
   * @return $this
   */
  public function setWeight(int $weight): self;

  /**
   * Sets the aggregate property.
   *
   * @param bool $aggregate
   *   (optional) The value to set for the aggregate property. Defaults to TRUE.
   *
   * @return $this
   *   The current instance of the class for method chaining.
   */
  public function setAggregate($aggregate = TRUE);

  /**
   * Check if settings type is aggregate.
   *
   * @return bool
   *   TRUE if aggregate.
   */
  public function isAggregate();

}
