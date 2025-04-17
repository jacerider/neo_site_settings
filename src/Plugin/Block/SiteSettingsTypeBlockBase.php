<?php

namespace Drupal\neo_site_settings\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\neo_site_settings\Entity\SiteSettings;

/**
 * Provides base for blocks that was to use a settings entity.
 */
class SiteSettingsTypeBlockBase extends BlockBase implements BlockPluginInterface {

  /**
   * The settings type to use.
   *
   * @var string
   */
  protected $siteSettingsTypeId = 'general';

  /**
   * The site settings entity.
   *
   * @var \Drupal\neo_site_settings\SiteSettingsInterface
   */
  protected $siteSettings;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
    $this->siteSettings = SiteSettings::load($this->siteSettingsTypeId);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return $this->siteSettings->getCacheTags();
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getMachineNameSuggestion() {
    return 'neo_site_settings_' . $this->siteSettingsTypeId;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    $dependencies = parent::calculateDependencies();
    $dependencies['config'][] = 'neo_site_settings.neo_site_settings_type.' . $this->siteSettingsTypeId;
    return $dependencies;
  }

}
