<?php

namespace Drupal\neo_site_settings\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\Core\Cache\Cache;
use Drupal\Component\Utility\Html;
use Drupal\neo_site_settings\Entity\SiteSettings;

/**
 * Provides a generic SiteSettings block.
 *
 * @Block(
 *   id = "neo_site_settings_field",
 *   admin_label = @Translation("Site Settings: Fields"),
 * )
 */
class SiteSettingsFieldBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity display repository.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected $entityDisplayRepository;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, EntityDisplayRepositoryInterface $entity_display_repository) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityDisplayRepository = $entity_display_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('entity_display.repository')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    $settings = parent::defaultConfiguration();
    $settings['site_settings_fields'] = [];
    $settings['site_settings_view_mode'] = '';
    // Set custom cache settings.
    if (isset($this->pluginDefinition['cache'])) {
      $settings['cache'] = $this->pluginDefinition['cache'];
    }
    return $settings;
  }

  /**
   * {@inheritdoc}
   */
  public function getMachineNameSuggestion() {
    return 'neo_site_settings_field';
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);

    $config = $this->getConfiguration();

    $view_modes = $this->entityDisplayRepository->getViewModes('neo_site_settings');
    $options = ['' => $this->t('Default')];
    foreach ($view_modes as $id => $view_mode) {
      $options[$id] = $view_mode['label'];
    }
    // Get view modes.
    $form['site_settings_view_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Select view mode to show'),
      '#options' => $options,
      '#default_value' => $config['site_settings_view_mode'],
    ];

    $group_class = 'group-order-weight';
    $form['site_settings_fields'] = [
      '#type' => 'table',
      '#header' => [
        'status' => $this->t('Status'),
        'label' => $this->t('Label'),
      ],
      '#tableselect' => FALSE,
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => $group_class,
        ],
      ],
    ];

    $options = array_merge(array_flip($config['site_settings_fields']), $this->getFieldOptions());
    foreach ($options as $key => $label) {
      $form['site_settings_fields'][$key]['#attributes']['class'][] = 'draggable';
      $form['site_settings_fields'][$key]['status'] = [
        '#type' => 'checkbox',
        '#default_value' => in_array($key, $config['site_settings_fields']),
      ];
      $form['site_settings_fields'][$key]['label']['#markup'] = $label;
    }

    return $form;
  }

  /**
   * Get field options.
   */
  protected function getFieldOptions() {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('neo_site_settings_type')->loadMultiple() as $type) {
      foreach ($this->entityFieldManager->getFieldDefinitions('neo_site_settings', $type->id()) as $field_name => $field) {
        if ($field instanceof FieldConfig) {
          $options["{$type->id()}.$field_name"] = $this->t('@neo_site_settings_type: %field_name', [
            '@neo_site_settings_type' => $type->label(),
            '%field_name' => $field->getLabel(),
          ]);
        }
      }
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $fields = array_filter($form_state->getValue(['site_settings_fields']), function ($item) {
      return !empty($item['status']);
    });
    $this->setConfigurationValue('site_settings_fields', array_keys($fields));
    $this->setConfigurationValue('site_settings_view_mode', $form_state->getValue('site_settings_view_mode'));
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $build = [];
    $config = $this->getConfiguration();
    foreach ($config['site_settings_fields'] as $weight => $key) {
      [$type_id, $field_name] = explode('.', $key);
      $neo_site_settings = SiteSettings::load($type_id);
      if ($neo_site_settings && $neo_site_settings->hasField($field_name) && !$neo_site_settings->get($field_name)->isEmpty()) {
        $build[$key] = $neo_site_settings->get($field_name)->view($config['site_settings_view_mode']);
        $build[$key]['#weight'] = $weight;
        $build[$key]['#attributes']['class'][] = 'settings-' . Html::getClass($type_id);
      }
    }
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    $tags = [];
    $config = $this->getConfiguration();
    foreach ($config['site_settings_fields'] as $key) {
      [$type_id, $field_name] = explode('.', $key);
      $neo_site_settings = SiteSettings::load($type_id);
      if ($neo_site_settings && $neo_site_settings->hasField($field_name) && !$neo_site_settings->get($field_name)->isEmpty()) {
        $tags = Cache::mergeTags($tags, $neo_site_settings->getCacheTags());
      }
    }
    return $tags;
  }

}
