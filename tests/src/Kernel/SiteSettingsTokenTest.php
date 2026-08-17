<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_site_settings\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\neo_site_settings\Entity\SiteSettingsType;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the site tokens resolve to, and what they make the page depend on.
 *
 * These eight tokens are the module's most externally-consumed surface: they
 * are bound in metatag's global defaults, where they fill og:street_address,
 * og:locality, og:region, og:postal_code, og:phone_number and the slots of a
 * serialized schema.org PostalAddress. A wrong value here is published in the
 * page head, and metatag omits a tag whose token resolves empty, so a failure
 * shows up as a missing tag rather than an error.
 *
 * The cacheability half matters just as much. The dependency used to be
 * registered inside a per-token branch behind a static memo, so whether the
 * page picked up the settings entity depended on which token happened to be
 * computed first, and an empty field registered nothing at all — meaning the
 * page would not re-render when the field was eventually filled in.
 *
 * @see neo_site_settings_tokens()
 */
#[Group('neo_site_settings')]
final class SiteSettingsTokenTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'link',
    'telephone',
    'neo_site_settings',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('neo_site_settings');

    SiteSettingsType::create([
      'id' => 'general',
      'label' => 'General',
      'aggregate' => TRUE,
    ])->save();

    foreach (['field_phone' => 'telephone', 'field_address' => 'string_long'] as $name => $type) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'neo_site_settings',
        'type' => $type,
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'neo_site_settings',
        'bundle' => 'general',
        'label' => $name,
      ])->save();
    }
  }

  /**
   * Populated fields resolve to the values a page head will carry.
   */
  public function testPopulatedFieldsResolve(): void {
    $this->saveGeneral([
      'field_phone' => '1-888-937-5150',
      'field_address' => "2805 Dallas Parkway, Suite 150, Plano, TX 75093",
    ]);

    $this->assertSame('1-888-937-5150', $this->replace('[site:phone]'));
    $this->assertSame('2805 Dallas Parkway', $this->replace('[site:address:street_1]'));
    $this->assertSame(', Suite 150', $this->replace('[site:address:street_2]'));
    $this->assertSame('Plano', $this->replace('[site:address:city]'));
    $this->assertSame('TX', $this->replace('[site:address:state]'));
    $this->assertSame('75093', $this->replace('[site:address:zip]'));
  }

  /**
   * The bare address token joins the stored lines rather than parsing them.
   */
  public function testBareAddressTokenJoinsTheStoredValue(): void {
    $this->saveGeneral(['field_address' => "123 Main St\nSpringfield, IL 62704"]);

    $this->assertSame('123 Main St, Springfield, IL 62704', $this->replace('[site:address]'));
  }

  /**
   * An empty field leaves its token unreplaced rather than emitting an empty.
   *
   * Metatag omits a tag whose value came back empty; what must not happen is
   * the literal "[site:address:city]" reaching the markup.
   */
  public function testEmptyFieldLeavesTheTokenUnreplaced(): void {
    $this->saveGeneral(['field_phone' => '1-888-937-5150']);

    $this->assertSame('[site:address:city]', $this->replace('[site:address:city]'));
    $this->assertSame('1-888-937-5150', $this->replace('[site:phone]'));
  }

  /**
   * Country resolves to an empty string because no branch can populate it.
   *
   * With an address present the token is replaced, just with nothing, which is
   * what makes metatag drop og:country_name and the addressCountry slot. That
   * is distinct from the address being absent entirely, where the token is not
   * replaced at all — see ::testEmptyFieldLeavesTheTokenUnreplaced().
   */
  public function testCountryResolvesToAnEmptyString(): void {
    $this->saveGeneral(['field_address' => '2805 Dallas Parkway, Plano, TX 75093']);

    $this->assertSame('', $this->replace('[site:address:country]'));
    // The sibling components are populated, so this is not simply a dead token.
    $this->assertSame('Plano', $this->replace('[site:address:city]'));
  }

  /**
   * The settings entity is registered as a dependency, even for an empty field.
   *
   * This is the regression that mattered: a page rendered while the address was
   * empty has to re-render once the address is filled in.
   */
  public function testDependencyIsRegisteredEvenWhenTheFieldIsEmpty(): void {
    $this->saveGeneral([]);

    $metadata = new BubbleableMetadata();
    $this->container->get('token')->replace('[site:address:city]', [], [], $metadata);

    $this->assertContains('neo_site_settings:general', $metadata->getCacheTags());
  }

  /**
   * Every address token registers the dependency, not just the first one.
   *
   * Metatag calls the token service once per tag value, so five separate calls
   * resolve address tokens on a single page. Each one has to carry the tag.
   */
  public function testEveryTokenRegistersTheDependency(): void {
    $this->saveGeneral(['field_address' => '2805 Dallas Parkway, Plano, TX 75093']);

    foreach (['[site:address:city]', '[site:address:state]', '[site:address:zip]', '[site:phone]'] as $token) {
      $metadata = new BubbleableMetadata();
      $this->container->get('token')->replace($token, [], [], $metadata);

      $this->assertContains(
        'neo_site_settings:general',
        $metadata->getCacheTags(),
        sprintf('%s registers the settings entity', $token),
      );
    }
  }

  /**
   * A site token this module does not provide picks up no dependency.
   *
   * The hook is called for every site token, so it has to return early rather
   * than making unrelated pages depend on the settings entity.
   */
  public function testUnrelatedSiteTokenGetsNoDependency(): void {
    $this->saveGeneral(['field_phone' => '1-888-937-5150']);

    $metadata = new BubbleableMetadata();
    $this->container->get('token')->replace('[site:name]', [], [], $metadata);

    $this->assertNotContains('neo_site_settings:general', $metadata->getCacheTags());
  }

  /**
   * Saves the general settings entity with the given field values.
   */
  private function saveGeneral(array $values): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_site_settings');
    $entity = $storage->loadOrCreateByType('general');
    foreach ($values as $field => $value) {
      $entity->set($field, $value);
    }
    $entity->save();
  }

  /**
   * Replaces a single token and returns the result.
   */
  private function replace(string $token): string {
    return $this->container->get('token')->replace($token, [], [], new BubbleableMetadata());
  }

}
