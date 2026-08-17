<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_site_settings\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_site_settings\Entity\SiteSettingsType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Who may edit site settings, expressed once as a table.
 *
 * The rule is a three-rung ladder: the administer permission, the edit-all
 * permission, or the per-bundle permission that SiteSettingsPermissions mints
 * for each type. It was written out by hand in four places and had already
 * drifted into four different rules — one copy silently dropped the administer
 * rung, another computed a result and discarded it. The table below is the
 * single statement of what the ladder is supposed to be, checked against both
 * entity types.
 *
 * The per-bundle permission name is also a frozen contract: the strings are
 * stored verbatim in user.role.*.yml on every consuming site, and Drupal drops
 * unrecognised permissions from a role silently, so a rename would read as an
 * unannounced lockout. testPermissionNamesAreStable() pins the format.
 *
 * @see \Drupal\neo_site_settings\SiteSettingsAccessControlHandler
 * @see \Drupal\neo_site_settings\SiteSettingsTypeAccessControlHandler
 * @see \Drupal\neo_site_settings\SiteSettingsPermissions
 */
#[Group('neo_site_settings')]
final class SiteSettingsAccessTest extends KernelTestBase {

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
    $this->installEntitySchema('user');
    $this->installEntitySchema('neo_site_settings');
    $this->installConfig(['user']);

    // Reserve uid 1, which bypasses every access check and would mask the rules
    // under test.
    User::create(['uid' => 1, 'name' => 'root'])->save();

    foreach (['general', 'social'] as $id) {
      SiteSettingsType::create([
        'id' => $id,
        'label' => ucfirst($id),
        'aggregate' => TRUE,
      ])->save();
    }
  }

  /**
   * The ladder: which permission grants edit access, and which does not.
   *
   * @return array
   *   Cases of [granted permissions, expected access].
   */
  public static function ladderCases(): array {
    return [
      'the administer permission grants it' => [
        ['administer neo_site_settings'],
        TRUE,
      ],
      'the edit-all permission grants it' => [
        ['edit all neo_site_settings'],
        TRUE,
      ],
      'the matching per-bundle permission grants it' => [
        ['edit general site settings'],
        TRUE,
      ],
      'a per-bundle permission for another bundle does not' => [
        ['edit social site settings'],
        FALSE,
      ],
      'an unrelated permission does not' => [
        ['access content'],
        FALSE,
      ],
      'no permissions at all does not' => [[], FALSE],
    ];
  }

  /**
   * The settings type grants page_update according to the ladder.
   */
  #[DataProvider('ladderCases')]
  public function testTypeAccessFollowsTheLadder(array $permissions, bool $expected): void {
    $account = $this->createAccountWith($permissions);
    $type = SiteSettingsType::load('general');

    $this->assertSame($expected, $type->access('page_update', $account));
  }

  /**
   * The settings entity grants update according to the same ladder.
   *
   * Both handlers implement the identical rule against different entity types,
   * so they are checked against the same table rather than separate ones.
   */
  #[DataProvider('ladderCases')]
  public function testEntityAccessFollowsTheLadder(array $permissions, bool $expected): void {
    $account = $this->createAccountWith($permissions);
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_site_settings');
    $entity = $storage->loadOrCreateByType('general');
    $entity->save();

    $this->assertSame($expected, $entity->access('update', $account));
  }

  /**
   * A permission is minted for every settings type, in a stable format.
   *
   * The names are stored in exported role config on every consuming site, so
   * the format cannot change without a hook_update_N that rewrites every
   * user.role.* object.
   */
  public function testPermissionNamesAreStable(): void {
    $permissions = $this->container->get('user.permissions')->getPermissions();

    $this->assertArrayHasKey('edit general site settings', $permissions);
    $this->assertArrayHasKey('edit social site settings', $permissions);
    $this->assertArrayHasKey('administer neo_site_settings', $permissions);
    $this->assertArrayHasKey('edit all neo_site_settings', $permissions);
  }

  /**
   * Creating a settings type mints its permission without a rebuild.
   */
  public function testPermissionIsMintedForNewType(): void {
    $this->assertArrayNotHasKey(
      'edit footer site settings',
      $this->container->get('user.permissions')->getPermissions(),
    );

    SiteSettingsType::create([
      'id' => 'footer',
      'label' => 'Footer',
      'aggregate' => FALSE,
    ])->save();

    $this->assertArrayHasKey(
      'edit footer site settings',
      $this->container->get('user.permissions')->getPermissions(),
    );
  }

  /**
   * Builds an authenticated account holding exactly the given permissions.
   */
  private function createAccountWith(array $permissions): User {
    static $index = 0;
    $index++;

    $role = Role::create([
      'id' => 'test_role_' . $index,
      'label' => 'Test role ' . $index,
    ]);
    foreach ($permissions as $permission) {
      $role->grantPermission($permission);
    }
    $role->save();

    $user = User::create([
      'name' => 'tester' . $index,
      'status' => 1,
      'roles' => [$role->id()],
    ]);
    $user->save();

    $this->assertNotSame(1, (int) $user->id(), 'uid 1 would bypass access checks.');
    $this->assertNotSame(RoleInterface::ANONYMOUS_ID, $role->id());

    return $user;
  }

}
