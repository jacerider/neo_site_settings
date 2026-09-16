<?php

declare(strict_types=1);

namespace Drupal\neo_site_settings\Plugin\ComponentValue;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_alchemist\Attribute\ComponentValue;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchResult;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchScope;
use Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchSourceInterface;
use Drupal\neo_alchemist\Match\MatcherReference;
use Drupal\neo_alchemist\Plugin\ComponentValue\ComponentValueProcessingModeTrait;
use Drupal\neo_alchemist\Shape\ComponentShapeChildrenMatchPluginInterface;
use Drupal\neo_alchemist\Shape\ComponentShapePluginInterface;
use Drupal\neo_alchemist\Value\ComponentValuePluginBase;
use Drupal\neo_alchemist\Value\ComponentValueProcessingModeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Iterates an entity reference field on a Site Settings entity.
 *
 * The curated counterpart to "Entity Query": instead of querying for content
 * by bundle and sort, it renders exactly the entities a site builder picked in
 * a reference field on a Site Settings entity — the two insights chosen for a
 * mega menu, say — in the order they were picked.
 *
 * It sits between the two providers that nearly do this and cannot:
 * - "Site Settings" yields the settings entity *itself* as the single row, so
 *   it fills an object prop but can never produce more than one item. A
 *   reference on it is reachable only as a hop in a field path
 *   (`field_project.title`), which reads delta 0 and nothing more.
 * - "Entity Reference" iterates exactly such a field, but sources it from the
 *   entity the component is placed on, so it is unavailable to a component
 *   with no host entity — which is every component in a menu region.
 *
 * Settings entities are singletons addressed by bundle, so this needs no
 * hardcoded entity ID and stays portable across environments.
 *
 * @see \Drupal\neo_site_settings\Plugin\ComponentValue\SiteSettingsValue
 * @see \Drupal\neo_alchemist\Plugin\ComponentValue\EntityReferenceValue
 */
#[ComponentValue(
  id: 'site_settings_reference',
  label: new TranslatableMarkup('Site Settings Reference'),
  description: new TranslatableMarkup('Iterate an entity reference field on a Site Settings entity to provide values from the referenced entities.'),
  group: 'providers',
  prop_types: [
    ComponentShapePluginInterface::ARRAY,
    ComponentShapePluginInterface::OBJECT,
  ],
  weight: 5,
)]
final class SiteSettingsReferenceValue extends ComponentValuePluginBase implements ContainerFactoryPluginInterface, ComponentValueProcessingModeInterface, ChildrenMatchSourceInterface {

  use DependencySerializationTrait;
  use ComponentValueProcessingModeTrait;

  /**
   * The entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The reference matcher.
   *
   * @var \Drupal\neo_alchemist\Match\MatcherReference
   */
  protected MatcherReference $matcherReference;

  /**
   * The children-match mapper.
   *
   * @var \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchMapper
   */
  protected ChildrenMatchMapper $childrenMatchMapper;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    $plugin_id,
    $plugin_definition,
    ComponentShapePluginInterface $shape,
    array $configuration,
    EntityTypeManagerInterface $entity_type_manager,
    MatcherReference $matcher_reference,
    ChildrenMatchMapper $children_match_mapper,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $shape, $configuration);
    $this->entityTypeManager = $entity_type_manager;
    $this->matcherReference = $matcher_reference;
    $this->childrenMatchMapper = $children_match_mapper;
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
      $container->get('neo_alchemist.matcher_reference'),
      $container->get('neo_alchemist.children_match_mapper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'bundle' => '',
      'reference' => '',
    ] + ChildrenMatchMapper::defaultConfiguration()
      + $this->processingModeDefaultConfiguration();
  }

  /**
   * {@inheritdoc}
   *
   * Blocks on empty, for the reason EntityReferenceValue documents: the prop
   * this fills carries schema examples that are editor scaffolding —
   * placeholder cards and placehold.co images. An unpicked reference means the
   * site builder chose to show nothing, so it must render as nothing rather
   * than fall through to those examples. Override the instance to
   * stop_when_found only when a fallback provider sits below it.
   */
  protected function processingModeDefault(): string {
    return ComponentValueProcessingModeInterface::MODE_BLOCK;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary(): array {
    $summary = [];
    if (($bundle = $this->configuration['bundle']) && ($reference = $this->configuration['reference'])) {
      $summary[] = $this->t('From %bundle: %field', [
        '%bundle' => $bundle,
        '%field' => explode(':', $reference)[0],
      ]);
    }
    // The mapper reads the whole shape; narrow the handle to it here, as the
    // form and value paths already do before their mapper calls.
    assert($this->shape instanceof ComponentShapeChildrenMatchPluginInterface);
    return array_merge($summary, $this->childrenMatchMapper->summary($this->shape, $this->configuration));
  }

  /**
   * Configuration form for the value provider plugin.
   */
  protected function configurationForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    assert($this->shape instanceof ComponentShapeChildrenMatchPluginInterface);
    $wrapperId = Html::getId(implode('-', $form['#parents']) . '-' . $this->getPluginId());
    $form['#id'] = $wrapperId;
    $form = $this->childrenMatchMapper->buildConfigurationForm($this, $this->shape, $form, $form_state, $this->configuration);
    return $this->buildProcessingModeForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function buildChildrenMatchSourceForm(array &$form, FormStateInterface $form_state): ?ChildrenMatchScope {
    $wrapperId = $form['#id'];
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
      '#description' => $this->t('Select the site settings that hold the reference field.'),
      '#default_value' => $bundleId,
      '#options' => $options,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if (!$bundleId || !isset($bundles[$bundleId])) {
      return NULL;
    }

    $reference = $this->configuration['reference'];
    $form['reference'] = [
      '#type' => 'select',
      '#title' => $this->t('Reference field'),
      '#description' => $this->t('The referenced entities become the items, in the order they are referenced.'),
      '#options' => $this->matcherReference->getReferencesAsOptions('neo_site_settings', $bundleId),
      '#default_value' => $reference,
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#ajax' => [
        'callback' => [static::class, 'refreshAjax'],
        'wrapper' => $wrapperId,
      ],
    ];

    if (!$reference) {
      return NULL;
    }

    $scope = $this->referenceScope($bundleId, $reference);
    if (!$scope) {
      $form['message'] = [
        '#markup' => $this->t('The selected reference field could not be resolved.'),
        '#prefix' => '<div class="messages messages--warning">',
        '#suffix' => '</div>',
      ];
    }
    return $scope;
  }

  /**
   * The entity type and bundle a reference on a settings bundle points at.
   *
   * Read off the field definition rather than by building a prototype entity:
   * the definition is the authority on both, and it is reachable with the
   * bundle in hand, which is the only way a bundle-only field like
   * field_insights is visible at all.
   *
   * @param string $bundleId
   *   The Site Settings bundle declaring the reference.
   * @param string $reference
   *   The reference key, as MatcherReference spells it.
   *
   * @return \Drupal\neo_alchemist\ChildrenMatch\ChildrenMatchScope|null
   *   The scope, or NULL when the key no longer resolves.
   */
  private function referenceScope(string $bundleId, string $reference): ?ChildrenMatchScope {
    $references = $this->matcherReference->getReferences('neo_site_settings', $bundleId);
    $definition = $references[$reference]['definition'] ?? NULL;
    if (!$definition instanceof FieldDefinitionInterface) {
      return NULL;
    }
    $targetType = $definition->getSetting('target_type');
    if (!$targetType) {
      return NULL;
    }
    // A reference restricted to exactly one bundle can offer that bundle's
    // fields; one restricted to several, or to none, has no single answer, and
    // ChildrenMatchScope's NULL bundle is the honest widening.
    $targetBundles = ($definition->getSetting('handler_settings')['target_bundles'] ?? NULL) ?: [];
    $targetBundle = count($targetBundles) === 1 ? (string) reset($targetBundles) : NULL;
    return new ChildrenMatchScope($targetType, $targetBundle);
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
    // Produce the value; the configurable processing mode (applied by the
    // pipeline) decides whether to claim it or fall through when empty.
    return $this->childrenMatchMapper->getValues($this, $this->shape, $this->configuration, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function getChildrenMatchEntities(): ChildrenMatchResult {
    $bundleId = $this->configuration['bundle'];
    $reference = $this->configuration['reference'];
    if (!$bundleId || !$reference) {
      return ChildrenMatchResult::unavailable();
    }
    /** @var \Drupal\neo_site_settings\SiteSettingsStorage $storage */
    $storage = $this->entityTypeManager->getStorage('neo_site_settings');
    $entity = $storage->loadOrCreateByType($bundleId);
    // Use the bundle list tag, not the entity tag: an unsaved settings entity
    // is new, and a new entity contributes no cache tags at all.
    $this->shape->getCacheableMetadata()
      ->addCacheTags(['neo_site_settings_list:' . $bundleId]);
    // A never-saved settings entity has no stored reference to follow, and
    // getReferenceField() answers NULL for it rather than guessing.
    $field = $this->matcherReference->getReferenceField($entity, $reference, $this->shape->getCacheableMetadata());
    // Only map when the reference actually resolves to entities. With zero
    // entities the mapper's non-iterable branch returns a map of per-child
    // empties that isProvidedValueEmpty() counts as NON-empty — which under
    // stop_when_found would claim, starving a fallback provider and
    // force-hiding every child. This mirrors EntityReferenceValue's guard.
    $entities = $field ? $field->referencedEntities() : [];
    return $entities ? ChildrenMatchResult::of($entities) : ChildrenMatchResult::emptyValue();
  }

  /**
   * {@inheritdoc}
   *
   * Unlike EntityReferenceValue this needs no host entity — the settings
   * entity is addressed by bundle — so the only gate is that the shape's
   * children can receive a distribution: iterable lists and expandable objects
   * (the `_aggregate` shape of an aggregated component being the important
   * one). Non-expandable object shapes such as link and heading stay excluded.
   */
  public static function isApplicable(ComponentShapePluginInterface $shape) {
    return $shape->isIterable() || $shape->isExpandable();
  }

}
