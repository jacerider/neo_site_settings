<?php

declare(strict_types=1);

namespace Drupal\neo_site_settings\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ComponentPropRenderable;
use Drupal\neo_alchemist\ComponentShapePluginInterface;
use Drupal\neo_alchemist\ComponentValuePluginBase;
use Drupal\neo_alchemist\ComponentValueProcessingModeInterface;
use Drupal\neo_alchemist\MatcherField;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Assigns a single field value from a Site Settings entity to a prop.
 *
 * A generic counterpart to the "Entity Field" (entity) provider: instead of
 * reading a field off the host entity the component renders on, it reads a
 * field off a Site Settings entity chosen by bundle. The field list is
 * shape-aware — only fields whose value is compatible with the target prop are
 * offered — so a single implementation serves many field and prop types.
 */
#[ComponentValue(
  id: 'site_settings_field',
  label: new TranslatableMarkup('Site Settings Field'),
  description: new TranslatableMarkup('Assign a single field value from a Site Settings entity.'),
  group: 'providers',
  ref_types: [
    '!' . ComponentShapePluginInterface::OBJECT,
  ],
  weight: 5,
)]
final class SiteSettingsFieldValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface {

  use DependencySerializationTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The field matcher.
   *
   * @var \Drupal\neo_alchemist\MatcherField
   */
  protected MatcherField $matcherField;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected RendererInterface $renderer;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    MatcherField $matcher_field,
    RendererInterface $renderer,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->matcherField = $matcher_field;
    $this->renderer = $renderer;
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
      $container->get('neo_alchemist.matcher_field'),
      $container->get('renderer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'bundle' => '',
      'field' => '',
      'render' => FALSE,
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
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;

    $bundleId = $this->configuration['bundle'];

    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Site Settings'),
      '#description' => $this->t('Select the Site Settings entity to read from.'),
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
      // Shape-aware options: only fields whose value is compatible with this
      // prop are listed, and each option key carries the property read path
      // (e.g. "field_copyright:value") consumed by getEntityValue() below.
      $form['field'] = [
        '#type' => 'select',
        '#title' => $this->t('Field'),
        '#description' => $this->t('Only fields compatible with this prop are listed.'),
        '#options' => $this->matcherField->getMatchesAsOptions($this->shape, 'neo_site_settings', $bundleId),
        '#default_value' => $this->configuration['field'],
        '#required' => TRUE,
        '#empty_option' => $this->t('- Select -'),
      ];

      $form['render'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Render through field display'),
        '#description' => $this->t('Pass the value through the field’s display (formatter and preprocess hooks) instead of reading the raw stored value. Enable this when a field’s configured display transforms the value — e.g. an injected copyright prefix.'),
        '#default_value' => $this->configuration['render'],
      ];
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
    $bundleId = $this->configuration['bundle'];
    $field = $this->configuration['field'];
    if (!$bundleId || !$field) {
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

    // Return what this provider produced, empty or not. The pipeline decides
    // precedence: an empty result that does not claim leaves the threaded
    // value intact, so a lower-weighted provider can still win. Returning
    // $value here instead would claim the example as if it were a real value
    // and halt the search.
    return !empty($this->configuration['render'])
      ? $this->renderFieldValue($entity, $field)
      : $this->rawFieldValue($entity, $field);
  }

  /**
   * Reads the raw stored field value for the target prop.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The Site Settings entity.
   * @param string $field
   *   The selected field match key.
   *
   * @return mixed
   *   A single field-item value the shape can denormalize, or NULL.
   */
  protected function rawFieldValue(ContentEntityInterface $entity, string $field): mixed {
    $result = $this->matcherField->getEntityValue(
      $entity,
      $field,
      default: NULL,
      cacheableMetadata: $this->shape->getCacheableMetadata(),
    );

    // The shape-aware field selector may return a field-level key (e.g.
    // "field_copyright"), for which getEntityValue yields the full delta list
    // ([['value' => '…']]). The prop's denormalize/adapt pipeline expects a
    // single field-item value, so collapse the list to its first delta and let
    // the shape extract the scalar (or multi-property value) itself. A
    // property-suffixed key (e.g. "field_x:value") already returns a bare
    // scalar and passes straight through.
    if (is_array($result) && array_is_list($result)) {
      $result = $result[0] ?? NULL;
    }

    return $result;
  }

  /**
   * Renders the field through its display so display transforms are applied.
   *
   * Routes the value through Drupal's field render pipeline (formatter +
   * hook_preprocess_field__*), so per-field display logic — such as an injected
   * copyright prefix — is honoured for any field, without special-casing it
   * here. Markup props receive the full renderable; scalar props receive the
   * rendered text with the field wrapper markup reduced away.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The Site Settings entity.
   * @param string $field
   *   The selected field match key.
   *
   * @return mixed
   *   A ComponentPropRenderable for markup props, a plain string for scalar
   *   props, or NULL when the field is empty.
   */
  protected function renderFieldValue(ContentEntityInterface $entity, string $field): mixed {
    $item = $this->matcherField->getEntityField($entity, $field, TRUE, $this->shape->getCacheableMetadata());
    if (!$item || $item->isEmpty()) {
      return NULL;
    }

    // Render with the field's configured "default" view-display formatter; this
    // goes through #theme: field, firing preprocess hooks for any field.
    $build = $item->view('default');
    $this->shape->addCacheableDependency(CacheableMetadata::createFromRenderArray($build));

    // Markup-capable props keep the full renderable untouched.
    if ($this->shape->getRef() === 'markup') {
      return ComponentPropRenderable::create($build);
    }

    // Scalar props want a clean string: render the themed field, drop theme
    // debug comments and the field wrapper markup, and decode entities (e.g.
    // "&copy;") so the value renders correctly without double-escaping.
    $html = (string) $this->renderer->renderInIsolation($build);
    $html = preg_replace('/<!--.*?-->/s', '', $html);
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));

    return $text !== '' ? $text : NULL;
  }

}
