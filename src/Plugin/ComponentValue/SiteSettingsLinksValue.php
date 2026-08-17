<?php

declare(strict_types=1);

namespace Drupal\neo_site_settings\Plugin\ComponentValue;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Populates a menu prop from the link fields of a Site Settings entity.
 *
 * Every filled-in link field on the selected Site Settings entity (e.g. the
 * "social" entity's Facebook / Instagram / … fields) becomes a menu item. The
 * icon, link text, target and ordering are taken from the entity's view
 * display — the neo_link formatter configured at
 * admin/structure/neo_site_settings_types/manage/<bundle>/display — so the menu
 * mirrors exactly how those links are set up there.
 */
#[ComponentValue(
  id: 'site_settings_links',
  label: new TranslatableMarkup('Site Settings Links'),
  description: new TranslatableMarkup('Populate menu links from the filled-in link fields of a Site Settings entity (e.g. social profiles).'),
  group: 'providers',
  ref_types: [
    'menu',
  ],
  weight: 5,
)]
final class SiteSettingsLinksValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface {

  use DependencySerializationTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity display repository service.
   *
   * @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface
   */
  protected EntityDisplayRepositoryInterface $entityDisplayRepository;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    EntityDisplayRepositoryInterface $entity_display_repository,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityDisplayRepository = $entity_display_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['shape'],
      $configuration['settings'],
      $container->get('entity_type.manager'),
      $container->get('entity_display.repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'bundle' => '',
    ] + $this->processingModeDefaultConfiguration();
  }

  /**
   * Get the available Site Settings bundle options.
   *
   * @return array
   *   Bundle labels keyed by machine name.
   */
  protected function getBundleOptions(): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('neo_site_settings_type')->loadMultiple() as $bundle) {
      $options[$bundle->id()] = $bundle->label();
    }
    asort($options);
    return $options;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Site Settings'),
      '#description' => $this->t('Every filled-in link field on this entity becomes a menu link. Icon, link text, target and order come from the entity’s <em>Manage display</em> (neo_link formatter).'),
      '#options' => $this->getBundleOptions(),
      '#default_value' => $this->configuration['bundle'],
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
    ];

    $form = $this->buildProcessingModeForm($form, $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    $bundleId = $this->configuration['bundle'];
    if (!$bundleId) {
      return $value;
    }

    /** @var \Drupal\neo_site_settings\SiteSettingsStorage $storage */
    $storage = $this->entityTypeManager->getStorage('neo_site_settings');
    $entity = $storage->loadOrCreateByType($bundleId);

    // Re-render whenever the settings entity changes. Use the bundle list
    // tag, not the entity tag: an unsaved settings entity is new, and a new
    // entity contributes no cache tags at all.
    $this->shape->getCacheableMetadata()
      ->addCacheTags(['neo_site_settings_list:' . $bundleId]);

    // The view display carries the per-field link presentation configured under
    // "Manage display": the neo_link formatter's icon, title and target, plus
    // the field ordering (weight).
    $viewDisplay = $this->entityDisplayRepository->getViewDisplay('neo_site_settings', $bundleId);

    $links = [];
    foreach ($entity->getFieldDefinitions() as $fieldName => $definition) {
      if ($definition->getType() !== 'link') {
        continue;
      }
      $field = $entity->get($fieldName);
      if ($field->isEmpty()) {
        continue;
      }
      /** @var \Drupal\link\LinkItemInterface $link */
      $link = $field->first();
      if (!$link->uri) {
        continue;
      }

      $component = $viewDisplay->getComponent($fieldName);
      $settings = $component['settings'] ?? [];

      // Prefer the formatter title, then the link's own title, then the label.
      $title = (string) (($settings['title'] ?? '') ?: ($link->title ?: $definition->getLabel()));

      // Only '_self' / '_blank' are valid link targets; the neo_link
      // formatter's "no target" option stores 0, which we treat as unset.
      $target = (string) ($settings['target'] ?? '');
      if (!in_array($target, ['_self', '_blank'], TRUE)) {
        $target = '';
      }

      // Merge the formatter's target/rel into the link options so consuming
      // components can honour them via neo_uri().
      $options = $link->options ?: [];
      $attributes = $options['attributes'] ?? [];
      if ($target !== '') {
        $attributes['target'] = $target;
      }
      if (!empty($settings['rel'])) {
        $attributes['rel'] = $settings['rel'];
      }
      if ($attributes) {
        $options['attributes'] = $attributes;
      }

      $url = [
        'title' => $title,
        'uri' => $link->uri,
        'options' => $options,
      ];
      if ($target !== '') {
        $url['target'] = $target;
      }

      $links[] = [
        'weight' => $component['weight'] ?? 0,
        'item' => [
          'title' => $title,
          'description' => '',
          'icon' => (string) ($settings['icon'] ?? ''),
          'in_active_trail' => FALSE,
          'is_expanded' => FALSE,
          'is_collapsed' => FALSE,
          'url' => $url,
        ],
      ];
    }

    usort($links, fn(array $a, array $b) => $a['weight'] <=> $b['weight']);
    return array_column($links, 'item');
  }

}
