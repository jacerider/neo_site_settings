<?php

namespace Drupal\neo_site_settings;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\neo_site_settings\Event\SiteSettingsPreloadEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Defines the storage handler class for neo config page entities.
 *
 * This extends the base storage class, adding required special handling for
 * neo config page entities.
 *
 * @ingroup neo_site_settings
 */
class SiteSettingsStorage extends SqlContentEntityStorage {

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * Constructs a CommentStorage object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_info
   *   An array of entity info for the entity type.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection to be used.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Cache backend instance to use.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Cache\MemoryCache\MemoryCacheInterface $memory_cache
   *   The memory cache.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $dispatcher
   *   The event dispatcher.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(
    EntityTypeInterface $entity_info,
    Connection $database,
    EntityFieldManagerInterface $entity_field_manager,
    CacheBackendInterface $cache,
    LanguageManagerInterface $language_manager,
    MemoryCacheInterface $memory_cache,
    EventDispatcherInterface $dispatcher,
    ?EntityTypeBundleInfoInterface $entity_type_bundle_info = NULL,
    ?EntityTypeManagerInterface $entity_type_manager = NULL,
  ) {
    parent::__construct($entity_info, $database, $entity_field_manager, $cache, $language_manager, $memory_cache, $entity_type_bundle_info, $entity_type_manager);
    $this->eventDispatcher = $dispatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_info) {
    return new static(
      $entity_info,
      $container->get('database'),
      $container->get('entity_field.manager'),
      $container->get('cache.entity'),
      $container->get('language_manager'),
      $container->get('entity.memory_cache'),
      $container->get('event_dispatcher'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function load($id) {
    $event = new SiteSettingsPreloadEvent($id);
    $this->eventDispatcher->dispatch($event, SiteSettingsPreloadEvent::EVENT_NAME);
    return parent::load($event->getTypeId());
  }

  /**
   * Loads config page entity by type and context.
   *
   * @param string $type_id
   *   Config page type to load.
   *
   * @return \Drupal\neo_site_settings\Entity\ExoSiteSettings|null
   *   Loaded neo config page entity object.
   */
  public function loadByType($type_id) {
    return $this->load($type_id);
  }

  /**
   * Loads config page entity by type and context.
   *
   * @param string $type_id
   *   Config page type to load.
   *
   * @return \Drupal\neo_site_settings\SiteSettingsInterface|null
   *   Loaded neo config page entity object.
   */
  public function loadOrCreateByType($type_id) {
    $entity = $this->loadByType($type_id);
    if (!$entity) {
      $event = new SiteSettingsPreloadEvent($type_id);
      $this->eventDispatcher->dispatch($event, SiteSettingsPreloadEvent::EVENT_NAME);
      $entity = $this->create([
        'id' => $event->getTypeId(),
        'bundle' => $type_id,
      ]);
    }
    return $entity;
  }

  /**
   * Check if there is a non-aggregated config page type.
   *
   * @return bool
   *   Return TRUE if there is a non-aggregated config page type.
   */
  public function hasNonAggregated() {
    $query = $this->entityTypeManager->getStorage('neo_site_settings_type')->getQuery();
    $query->condition('aggregate', 0);
    return $query->count()->accessCheck(FALSE)->execute() > 0;
  }

}
