<?php

declare(strict_types=1);

namespace Drupal\neo_site_settings\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\MatcherField;
use Drupal\neo_alchemist\MatcherReference;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueChildrenMatchTrait;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Plugin implementation of the neo_component_value_provider.
 */
#[ComponentValue(
  id: 'site_settings',
  label: new TranslatableMarkup('Site Settings'),
  description: new TranslatableMarkup('Load a Site Settings entity to provide values from its fields.'),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::OBJECT,
  ],
  weight: 5,
)]
final class SiteSettingsValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface {

  use DependencySerializationTrait;
  use ComponentValueChildrenMatchTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    EventDispatcherInterface $event_dispatcher,
    MatcherField $matcher_field,
    MatcherReference $matcher_reference,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->eventDispatcher = $event_dispatcher;
    $this->matcherField = $matcher_field;
    $this->matcherReference = $matcher_reference;
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
      $container->get('event_dispatcher'),
      $container->get('neo_alchemist.matcher_field'),
      $container->get('neo_alchemist.matcher_reference')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'bundle' => '',
    ] + $this->childrenMatchDefaultConfiguration()
      + $this->processingModeDefaultConfiguration();
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    assert($this->shape instanceof ComponentShapeChildrenMatchPluginInterface);
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;

    $bundleId = $this->configuration['bundle'];

    $bundles = $this->entityTypeManager->getStorage('neo_site_settings_type')->loadMultiple();
    $options = [];
    foreach ($bundles as $bundle) {
      $options[$bundle->id()] = $bundle->label();
    }
    asort($options);

    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Site Settings'),
      '#description' => $this->t('Select the site settings to load.'),
      '#default_value' => $bundleId,
      '#options' => $options,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if ($bundleId && isset($bundles[$bundleId])) {
      $form += $this->buildChildrenMatchConfigurationForm($this->shape, $form, $form_state, 'neo_site_settings', $bundleId, $this->configuration);
    }

    $form = $this->buildProcessingModeForm($form, $form_state);

    return $form;
  }

  /**
   * Ajax callback.
   */
  public static function refreshAjax(array $form, FormStateInterface $form_state) {
    $trigger = $form_state->getTriggeringElement();
    return NestedArray::getValue($form, array_slice($trigger['#array_parents'], 0, -1));
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
    if (!$this->shape instanceof ComponentShapeChildrenMatchPluginInterface) {
      return $value;
    }

    $bundleId = $this->configuration['bundle'];
    if ($bundleId) {
      /** @var \Drupal\neo_site_settings\SiteSettingsStorage $storage */
      $storage = $this->entityTypeManager->getStorage('neo_site_settings');
      $entity = $storage->loadOrCreateByType($bundleId);
      // Use the bundle list tag, not the entity tag: an unsaved settings
      // entity is new, and a new entity contributes no cache tags at all.
      $this->shape->getCacheableMetadata()
        ->addCacheTags(['neo_site_settings_list:' . $bundleId]);
      return $this->getChildrenMatchValues($this->shape, [$entity], $this->configuration);
    }
    // Can't act: pass the threaded value through rather than wiping it to NULL.
    return $value;
  }

}
