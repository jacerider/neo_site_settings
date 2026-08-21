<?php

declare(strict_types=1);

namespace Drupal\neo_site_settings\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\media\MediaInterface;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\Shape\ComponentShapeMediaPluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProvision;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Falls back to a Site Settings media field when a media prop is empty.
 *
 * A common need when a media prop is populated from queried/referenced content
 * (e.g. the "Entity Query" provider filling per-item images from a taxonomy
 * term's field_media): individual entities frequently leave the media field
 * empty, and the site builder wants a shared fallback the client can manage.
 *
 * This provider is intentionally a *fallback*, not authoritative: it only
 * supplies a value when the prop has resolved empty, reading a media entity
 * from a chosen field on a chosen Site Settings entity. Because media shapes
 * resolve through the default-value pipeline (ImageShape enables the default
 * option), this runs per-item — including for the empty items that would
 * otherwise be pruned — and keeps them by providing the fallback media.
 *
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\MediaValue::provideDefaultValue()
 * @see \Drupal\neo_site_settings\Plugin\ComponentValue\SiteSettingsFieldValue
 */
#[ComponentValue(
  id: 'site_settings_fallback_media',
  label: new TranslatableMarkup('Fallback Media (Site Settings)'),
  description: new TranslatableMarkup('When empty, fall back to a media field on a Site Settings entity.'),
  group: 'providers',
  // Inline so it can be toggled on a child media sub-prop from a provider's
  // "Shape Fields" section (e.g. the per-item image of an Entity Query list),
  // not only on a top-level media prop.
  inline: TRUE,
  // After media (10) and entity/entity_query (5) so real content always wins;
  // before default (1000) so the Site Settings fallback beats the component's
  // example default.
  weight: 15,
)]
final class SiteSettingsFallbackMediaValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface {

  use DependencySerializationTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The entity field manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected EntityFieldManagerInterface $entityFieldManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    EntityFieldManagerInterface $entity_field_manager,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
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
      $container->get('entity_field.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return $shape instanceof ComponentShapeMediaPluginInterface;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'bundle' => '',
      'field' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function isEditable(): bool {
    return FALSE;
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
   * Get the media reference field options for a Site Settings bundle.
   *
   * Only entity_reference fields targeting media whose bundles overlap the
   * media types this prop supports are offered, so an image prop lists only
   * image-media fields.
   *
   * @param string $bundle
   *   The Site Settings bundle.
   *
   * @return array
   *   Field labels keyed by field machine name.
   */
  protected function getMediaFieldOptions(string $bundle): array {
    $options = [];
    $shape = $this->shape;
    if (!$shape instanceof ComponentShapeMediaPluginInterface) {
      return $options;
    }
    $supported = $shape->getSupportedMediaTypes();
    foreach ($this->entityFieldManager->getFieldDefinitions('neo_site_settings', $bundle) as $fieldName => $definition) {
      if ($definition->getType() !== 'entity_reference') {
        continue;
      }
      if ($definition->getSetting('target_type') !== 'media') {
        continue;
      }
      $targetBundles = $definition->getSetting('handler_settings')['target_bundles'] ?? [];
      // No bundle restriction means all media types are allowed; otherwise the
      // field must be able to hold at least one supported media type.
      if ($targetBundles && !array_intersect($targetBundles, $supported)) {
        continue;
      }
      $options[$fieldName] = $definition->getLabel();
    }
    asort($options);
    return $options;
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;

    $bundleId = $this->configuration['bundle'];

    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Site Settings'),
      '#description' => $this->t('Select the Site Settings entity to read the fallback media from.'),
      '#options' => $this->getBundleOptions(),
      '#default_value' => $bundleId,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if ($bundleId) {
      $form['field'] = [
        '#type' => 'select',
        '#title' => $this->t('Media field'),
        '#description' => $this->t('Only media fields compatible with this prop are listed.'),
        '#options' => $this->getMediaFieldOptions($bundleId),
        '#default_value' => $this->configuration['field'],
        '#required' => TRUE,
        '#empty_option' => $this->t('- Select -'),
      ];
    }

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
   *
   * Every path that cannot supply a fallback offers the threaded value back
   * untouched, which is how this provider abstains. The one path that does
   * supply one claims it, so the fallback outranks the weight-1000 "default"
   * provider that would otherwise put the component's example back.
   */
  public function provide(mixed $value): ComponentValueProvision {
    $shape = $this->shape;
    if (!$shape instanceof ComponentShapeMediaPluginInterface) {
      return ComponentValueProvision::offer($value);
    }

    // Only fill when nothing real is present. isProvidedValueEmpty() ignores
    // the "size" key the media_image_size modifier seeds, so an otherwise-empty
    // media value still triggers the fallback.
    if (!$this->shape->isProvidedValueEmpty($value)) {
      return ComponentValueProvision::offer($value);
    }

    $bundleId = $this->configuration['bundle'];
    $field = $this->configuration['field'];
    if (!$bundleId || !$field) {
      return ComponentValueProvision::offer($value);
    }

    /** @var \Drupal\neo_site_settings\SiteSettingsStorage $storage */
    $storage = $this->entityTypeManager->getStorage('neo_site_settings');
    $entity = $storage->loadOrCreateByType($bundleId);

    // Re-render whenever the settings entity changes. Use the bundle list
    // tag, not the entity tag: an unsaved settings entity is new, and a new
    // entity contributes no cache tags at all.
    $this->shape->getCacheableMetadata()
      ->addCacheTags(['neo_site_settings_list:' . $bundleId]);

    if (!$entity->hasField($field) || $entity->get($field)->isEmpty()) {
      return ComponentValueProvision::offer($value);
    }

    $media = $entity->get($field)->entity;
    if (!$media instanceof MediaInterface) {
      return ComponentValueProvision::offer($value);
    }

    // Re-render whenever the fallback media changes.
    $this->shape->addCacheableDependency($media);

    $mediaValue = $shape->getValueFromMedia($media);
    if (!empty($mediaValue)) {
      // Make the fallback authoritative over lower-weighted providers such as
      // the weight-1000 "default" provider.
      return ComponentValueProvision::claim($mediaValue);
    }

    return ComponentValueProvision::offer($value);
  }

  /**
   * {@inheritdoc}
   */
  public function provideDefaultValue(mixed $value): mixed {
    return $this->provide($value)->getValue();
  }

}
