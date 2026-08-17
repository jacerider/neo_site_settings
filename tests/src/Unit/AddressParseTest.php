<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_site_settings\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The address parser's contract, shape by shape.
 *
 * This parser feeds eight metatag bindings — og:street_address, og:locality,
 * og:region, og:postal_code, og:country_name, og:phone_number and the
 * streetAddress/addressLocality slots of a serialized schema.org
 * PostalAddress. Every defect it has had was silent: a dropped unit line, a
 * city column filled with "Apt 4", a state that stopped matching because the
 * input was lower-cased. Nothing errored, the meta tags simply carried the
 * wrong thing.
 *
 * The table below is therefore the point of the file. It is not aiming at
 * coverage of the branches; it pins what each *shape of input* is supposed to
 * yield, because that is the thing that regressed.
 *
 * @see neo_site_settings_address_parse()
 */
#[Group('neo_site_settings')]
final class AddressParseTest extends UnitTestCase {

  /**
   * Every key the parser promises to return.
   */
  private const KEYS = [
    'street_number',
    'street_name',
    'unit',
    'city',
    'state',
    'zip',
    'country',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once __DIR__ . '/../../../neo_site_settings.tokens.inc';
  }

  /**
   * Input shapes and the components each is expected to yield.
   *
   * Only the non-empty components are listed per case; anything absent is
   * asserted to be an empty string, so a case that starts populating a field it
   * should not fails here.
   *
   * @return array
   *   Cases of [address string, expected non-empty components].
   */
  public static function addressCases(): array {
    return [
      // The shape this module was actually deployed against.
      'two lines, comma before state' => [
        "12821 16th Ave South\nBurnsville, MN 55337",
        [
          'street_number' => '12821',
          'street_name' => '16th Ave South',
          'city' => 'Burnsville',
          'state' => 'MN',
          'zip' => '55337',
        ],
      ],
      // Textarea input arrives with CRLF; the \r must not survive into a value.
      'two lines with CRLF endings' => [
        "12821 16th Ave South\r\nBurnsville, MN 55337",
        [
          'street_number' => '12821',
          'street_name' => '16th Ave South',
          'city' => 'Burnsville',
          'state' => 'MN',
          'zip' => '55337',
        ],
      ],
      'two lines, no comma before state' => [
        "123 Main St\nSpringfield IL 62704",
        [
          'street_number' => '123',
          'street_name' => 'Main St',
          'city' => 'Springfield',
          'state' => 'IL',
          'zip' => '62704',
        ],
      ],
      // Regression: the unit line used to be dropped between the shift and the
      // pop, so [site:address:street_2] rendered empty.
      'three lines, unit on its own line' => [
        "123 Main St\nApt 4\nSpringfield, IL 62704",
        [
          'street_number' => '123',
          'street_name' => 'Main St',
          'unit' => 'Apt 4',
          'city' => 'Springfield',
          'state' => 'IL',
          'zip' => '62704',
        ],
      ],
      'two lines, unit inline on the street' => [
        "123 Main St Apt 4\nSpringfield, IL 62704",
        [
          'street_number' => '123',
          'street_name' => 'Main St',
          'unit' => 'Apt 4',
          'city' => 'Springfield',
          'state' => 'IL',
          'zip' => '62704',
        ],
      ],
      // Regression: a recipient or company line first meant the street was
      // never found, because the street was read positionally.
      'four lines, company line first' => [
        "Acme Corp\n123 Main St\nSuite 900\nSpringfield, IL 62704",
        [
          'street_number' => '123',
          'street_name' => 'Main St',
          'unit' => 'Suite 900',
          'city' => 'Springfield',
          'state' => 'IL',
          'zip' => '62704',
        ],
      ],
      'single line, three comma parts' => [
        '123 Main St, Springfield, IL 62704',
        [
          'street_number' => '123',
          'street_name' => 'Main St',
          'city' => 'Springfield',
          'state' => 'IL',
          'zip' => '62704',
        ],
      ],
      // Regression: the city used to be taken positionally from the second
      // comma part, so it reported "Apt 4" and found no state or ZIP at all.
      'single line, four comma parts' => [
        '123 Main St, Apt 4, Springfield, IL 62704',
        [
          'street_number' => '123',
          'street_name' => 'Main St',
          'unit' => 'Apt 4',
          'city' => 'Springfield',
          'state' => 'IL',
          'zip' => '62704',
        ],
      ],
      // Regression: the street regex was case-insensitive but the state one was
      // not, so a lower-cased state lost the city and the ZIP along with it.
      'lower-cased state is matched and normalised' => [
        "123 Main St\nspringfield, il 62704",
        [
          'street_number' => '123',
          'street_name' => 'Main St',
          'city' => 'springfield',
          'state' => 'IL',
          'zip' => '62704',
        ],
      ],
      'zip plus four' => [
        "123 Main St\nSpringfield, IL 62704-1234",
        [
          'street_number' => '123',
          'street_name' => 'Main St',
          'city' => 'Springfield',
          'state' => 'IL',
          'zip' => '62704-1234',
        ],
      ],
      'hyphenated street number' => [
        "123-A Main St\nSpringfield, IL 62704",
        [
          'street_number' => '123-A',
          'street_name' => 'Main St',
          'city' => 'Springfield',
          'state' => 'IL',
          'zip' => '62704',
        ],
      ],
      // Best effort: no state or ZIP to anchor on, but a street and a city are
      // still recoverable.
      'street and city only' => [
        '123 Main St, Springfield',
        [
          'street_number' => '123',
          'street_name' => 'Main St',
          'city' => 'Springfield',
        ],
      ],
      // A lone fragment is not enough to call anything a city.
      'single fragment yields nothing' => ['Springfield', []],
      'empty string yields nothing' => ['', []],
      'whitespace only yields nothing' => ["  \n  ", []],
    ];
  }

  /**
   * Each input shape yields exactly the components listed for it.
   */
  #[DataProvider('addressCases')]
  public function testAddressComponents(string $address, array $expected): void {
    $actual = neo_site_settings_address_parse($address);

    foreach (self::KEYS as $key) {
      $this->assertSame(
        $expected[$key] ?? '',
        $actual[$key],
        sprintf('Component "%s" for address %s', $key, var_export($address, TRUE)),
      );
    }
  }

  /**
   * The result is a total record: all seven keys, always, whatever the input.
   *
   * The consumers in hook_tokens() read every key unconditionally, so a parse
   * that returned a partial array would emit undefined-key warnings into the
   * rendered meta tags rather than failing visibly.
   */
  #[DataProvider('addressCases')]
  public function testResultAlwaysHasEveryKey(string $address): void {
    $actual = neo_site_settings_address_parse($address);

    $this->assertSame(self::KEYS, array_keys($actual));
    foreach ($actual as $key => $value) {
      $this->assertIsString($value, sprintf('Component "%s" is a string', $key));
    }
  }

  /**
   * Country is structurally unreachable, and callers depend on knowing that.
   *
   * [site:address:country] is bound in metatag config on every site that
   * installs this module. No parse branch populates it, so the token resolves
   * empty and metatag omits the tag. The key exists purely so the token does
   * not fall through to the match arm that returns the whole address.
   */
  #[DataProvider('addressCases')]
  public function testCountryIsNeverPopulated(string $address): void {
    $this->assertSame('', neo_site_settings_address_parse($address)['country']);
  }

}
